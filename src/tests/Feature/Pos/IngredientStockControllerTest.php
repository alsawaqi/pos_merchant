<?php

declare(strict_types=1);

/**
 * P-G4 — central ingredient warehouse, the ingredient twin of
 * ProductStockControllerTest.
 *
 * Covers: receive → central warehouse; allocate warehouse → branches (debits
 * the pool, credits each branch's pos_branch_stock, writes paired ledger
 * rows); over-allocation rejected; Receive & Distribute in one call + the
 * undistributed remainder + atomic rollback; branch→branch transfer through
 * the dialog endpoint (a REAL BranchTransfer) + overdraw rejected; central
 * adjust with required note; cross-tenant isolation; and the report guards —
 * central (branch_id NULL) rows never count as branch consumption/depletion
 * and never show in a branch's movement ledger.
 */

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\BranchTransfer;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function warehouseIngredient(array $ctx, string $name = 'Sugar'): Ingredient
{
    return Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => $name, 'unit' => 'kg']);
}

function ingredientBranchQty(int $branchId, int $ingredientId): ?string
{
    // Read through the model so the decimal:3 cast formats consistently.
    $row = BranchStock::query()
        ->where('branch_id', $branchId)
        ->where('ingredient_id', $ingredientId)
        ->first();

    return $row === null ? null : (string) $row->quantity;
}

it('receives a purchase into the central warehouse', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);

    $res = $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 
        'quantity' => '100',
        'note' => 'Bought 100 kg of sugar',
    ])->assertOk();

    expect($res->json('data.central_quantity'))->toBe('100.000');
    expect($res->json('data.unit'))->toBe('kg');

    $central = IngredientStock::query()->where('ingredient_id', $sugar->id)->firstOrFail();
    expect((string) $central->quantity)->toBe('100.000');

    $movement = StockMovement::query()->where('ingredient_id', $sugar->id)->firstOrFail();
    expect($movement->movement_type->value)->toBe('received');
    expect($movement->branch_id)->toBeNull();
    expect((string) $movement->quantity)->toBe('100.000');
});

it('allocates the warehouse across branches and debits the pool', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $sugar = warehouseIngredient($ctx);

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '100'])->assertOk();

    $res = $this->postJson("/api/ingredients/{$sugar->uuid}/stock/allocate", [
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '20'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '25'],
        ],
        'note' => 'Weekly split',
    ])->assertOk();

    // Central 100 - 45 = 55.
    expect($res->json('data.central_quantity'))->toBe('55.000');
    expect(ingredientBranchQty($ctx['branch']->id, $sugar->id))->toBe('20.000');
    expect(ingredientBranchQty($branchB->id, $sugar->id))->toBe('25.000');

    // Ledger: received + (allocation_out + allocation_in) x2.
    expect(StockMovement::query()->where('ingredient_id', $sugar->id)->count())->toBe(5);
    expect(StockMovement::query()->where('ingredient_id', $sugar->id)->whereNull('branch_id')->count())->toBe(3);
});

it('rejects allocating more than the central balance', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '10'])->assertOk();

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '25']],
    ])->assertStatus(422);

    // Nothing moved — the warehouse is untouched and no branch row exists.
    expect((string) IngredientStock::query()->where('ingredient_id', $sugar->id)->firstOrFail()->quantity)->toBe('10.000');
    expect(BranchStock::query()->where('ingredient_id', $sugar->id)->exists())->toBeFalse();
});

it('receives a bulk purchase and distributes it across branches in one call', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $branchC = Branch::factory()->for($ctx['company'], 'company')->create();
    $sugar = warehouseIngredient($ctx);

    // The spec example: 100 kg in — 20 to A, 20 to B, 25 to C, 35 stays.
    $res = $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '100',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '20'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '20'],
            ['branch_uuid' => $branchC->uuid, 'quantity' => '25'],
        ],
        'note' => 'Sugar delivery',
    ])->assertOk();

    expect($res->json('data.central_quantity'))->toBe('35.000');
    expect(ingredientBranchQty($ctx['branch']->id, $sugar->id))->toBe('20.000');
    expect(ingredientBranchQty($branchB->id, $sugar->id))->toBe('20.000');
    expect(ingredientBranchQty($branchC->id, $sugar->id))->toBe('25.000');

    // Ledger: received + (allocation_out + allocation_in) x3.
    expect(StockMovement::query()->where('ingredient_id', $sugar->id)->count())->toBe(7);
    expect(StockMovement::query()->where('ingredient_id', $sugar->id)->where('movement_type', 'received')->count())->toBe(1);
});

it('rejects distributing more than the received total and writes nothing', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $sugar = warehouseIngredient($ctx);

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '50',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '30'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '30'],
        ],
    ])->assertStatus(422);

    // Atomic: no central row, no branch rows, no ledger.
    expect(IngredientStock::query()->where('ingredient_id', $sugar->id)->exists())->toBeFalse();
    expect(BranchStock::query()->where('ingredient_id', $sugar->id)->exists())->toBeFalse();
    expect(StockMovement::query()->where('ingredient_id', $sugar->id)->count())->toBe(0);
});

it('transfers stock between branches as a real branch transfer', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $sugar = warehouseIngredient($ctx);
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '30',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '30']],
    ])->assertOk();

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid,
        'to_branch_uuid' => $branchB->uuid,
        'quantity' => '12',
    ])->assertOk();

    expect(ingredientBranchQty($ctx['branch']->id, $sugar->id))->toBe('18.000');
    expect(ingredientBranchQty($branchB->id, $sugar->id))->toBe('12.000');

    // The dialog transfer is the SAME machinery as the Transfers tab —
    // a BranchTransfer header exists and the ledger pair references it.
    $transfer = BranchTransfer::query()->firstOrFail();
    expect((int) $transfer->from_branch_id)->toBe((int) $ctx['branch']->id);
    expect((int) $transfer->to_branch_id)->toBe((int) $branchB->id);
    expect(StockMovement::query()->where('reference_type', BranchTransfer::class)->where('reference_id', $transfer->id)->count())->toBe(2);
});

it('rejects a transfer that overdraws the source branch', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $sugar = warehouseIngredient($ctx);
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '5',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5']],
    ])->assertOk();

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid,
        'to_branch_uuid' => $branchB->uuid,
        'quantity' => '9',
    ])->assertStatus(422);

    expect(ingredientBranchQty($ctx['branch']->id, $sugar->id))->toBe('5.000');
    expect(BranchTransfer::query()->count())->toBe(0);
});

it('adjusts the central warehouse with a required note', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '10'])->assertOk();

    // branch_uuid omitted = the central pool.
    $res = $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'signed_quantity' => '-3',
        'note' => 'Spilled a bag in the warehouse',
    ])->assertOk();

    expect($res->json('data.central_quantity'))->toBe('7.000');

    $adjustment = StockMovement::query()
        ->where('ingredient_id', $sugar->id)
        ->where('movement_type', 'adjustment')
        ->firstOrFail();
    expect($adjustment->branch_id)->toBeNull();
});

it('rejects an adjustment with no note', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'signed_quantity' => '5',
    ])->assertStatus(422);
});

it('404s on an ingredient owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Ingredient::factory()->for($other, 'company')->create();

    $this->getJson("/api/ingredients/{$foreign->uuid}/stock")->assertNotFound();
});

it('keeps central rows out of branch consumption and depletion reports', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);

    // 100 in, 40 to the branch (central allocation_out -40), then a CENTRAL
    // adjust-down (-3, type 'adjustment' — the one consumption-listed type
    // central writes) and a BRANCH adjust-down (-2).
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '100',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '40']],
    ])->assertOk();
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'signed_quantity' => '-3',
        'note' => 'Warehouse spillage',
    ])->assertOk();
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'branch_uuid' => $ctx['branch']->uuid,
        'signed_quantity' => '-2',
        'note' => 'Branch recount',
    ])->assertOk();

    $today = now()->toDateString();

    // Consumption counts ONLY the branch adjustment (-2) — not the central
    // -3 and not the central allocation_out -40.
    $rows = $this->getJson("/api/reports/inventory-consumption?date_from={$today}&date_to={$today}")
        ->assertOk()->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['consumed'])->toBe('2.000');

    // Loss & Waste depletion likewise sums branch rows only.
    $shortfall = $this->getJson("/api/reports/loss-waste?date_from={$today}&date_to={$today}")
        ->assertOk()->json('data.shortfall');
    expect($shortfall)->toHaveCount(1);
    expect($shortfall[0]['total_depletion'])->toBe('2.000');
});

it('keeps central rows out of a branch movement ledger', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", ['no_cost' => true, 
        'quantity' => '10',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '4']],
    ])->assertOk();

    // The branch ledger shows only its allocation_in; the per-ingredient
    // warehouse ledger shows everything (central + branch rows).
    $branchRows = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock-movements")
        ->assertOk()->json('data');
    expect($branchRows)->toHaveCount(1);
    expect($branchRows[0]['movement_type'])->toBe('allocation_in');

    $allRows = $this->getJson("/api/ingredients/{$sugar->uuid}/stock/movements")
        ->assertOk()->json('data');
    expect($allRows)->toHaveCount(3);
});

it('blocks deleting an ingredient that still holds warehouse stock', function (): void {
    $ctx = makeMerchantActor();
    $sugar = warehouseIngredient($ctx);
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '10'])->assertOk();

    // Branches are all at zero, but the WAREHOUSE holds 10 — deleting now
    // would orphan a real physical asset (the pre-G4 guard only saw branches).
    $this->deleteJson("/api/ingredients/{$sugar->uuid}")->assertStatus(422);
    expect(Ingredient::query()->whereKey($sugar->id)->exists())->toBeTrue();

    // Zeroing the warehouse unblocks the delete.
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'signed_quantity' => '-10',
        'note' => 'Cleared out',
    ])->assertOk();
    $this->deleteJson("/api/ingredients/{$sugar->uuid}")->assertStatus(204);
});

it('counts warehouse receives in the purchasing report without double-billing allocations', function (): void {
    $ctx = makeMerchantActor();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Sugar', 'unit' => 'kg', 'default_unit_cost' => '0.500']);

    // 100 kg into the warehouse at the 0.500 snapshot = 50.000 spend.
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '100'])->assertOk();

    $today = now()->toDateString();
    $data = $this->getJson("/api/reports/restock-purchasing?date_from={$today}&date_to={$today}")
        ->assertOk()->json('data');
    expect($data['headline']['total_cost'])->toBe('50.000');
    expect($data['by_branch'])->toHaveCount(1);
    expect($data['by_branch'][0]['branch_id'])->toBeNull();
    expect($data['by_branch'][0]['branch_name'])->toBe('Warehouse');

    // Allocating the same stock out to a branch must NOT bill it again.
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '40']],
    ])->assertOk();
    $after = $this->getJson("/api/reports/restock-purchasing?date_from={$today}&date_to={$today}")
        ->assertOk()->json('data');
    expect($after['headline']['total_cost'])->toBe('50.000');
});

it('forbids a viewer role from mutating the warehouse', function (): void {
    // CashierSupervisor holds inventory.view but not inventory.manage.
    $ctx = makeMerchantActor(App\Enums\MerchantRole::CashierSupervisor->value);
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '5'])
        ->assertForbidden();
});

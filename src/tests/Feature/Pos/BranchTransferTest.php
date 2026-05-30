<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Branch transfers (§5.6) — immediate atomic stock move between two branches.
 *
 * Each line writes a paired transfer_out (source) + transfer_in (destination)
 * movement via the canonical WriteStockMovementAction, so branch_stock stays in
 * lock-step with the ledger. Source must hold enough (no negative stock). Gated
 * on inventory.manage (write) / inventory.view (read).
 */
function seedBranchStock(int $branchId, int $ingredientId, string $qty): void
{
    DB::table('pos_branch_stock')->insert([
        'branch_id' => $branchId, 'ingredient_id' => $ingredientId,
        'quantity' => $qty, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function balance(int $branchId, int $ingredientId): string
{
    // Normalise to 3 decimals — sqlite returns the decimal column as a bare
    // string ('6'), Postgres as '6.000'; format so assertions are driver-agnostic.
    $raw = DB::table('pos_branch_stock')
        ->where('branch_id', $branchId)->where('ingredient_id', $ingredientId)
        ->value('quantity');

    return number_format((float) $raw, 3, '.', '');
}

it('moves stock from one branch to another atomically', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk', 'default_unit_cost' => '2.000']);

    seedBranchStock($from->id, $milk->id, '10.000');

    $res = $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '4.000']],
    ])->assertCreated();

    expect($res->json('data.from_branch_id'))->toBe($from->id);
    expect($res->json('data.to_branch_id'))->toBe($to->id);
    expect($res->json('data.lines'))->toHaveCount(1);
    expect($res->json('data.lines.0.quantity'))->toBe('4.000');

    // Balances moved.
    expect(balance($from->id, $milk->id))->toBe('6.000');
    expect(balance($to->id, $milk->id))->toBe('4.000');

    // Paired ledger movements written, both referencing the transfer.
    $this->assertDatabaseHas('pos_stock_movements', [
        'branch_id' => $from->id, 'ingredient_id' => $milk->id,
        'movement_type' => 'transfer_out', 'quantity' => '-4.000',
    ]);
    $this->assertDatabaseHas('pos_stock_movements', [
        'branch_id' => $to->id, 'ingredient_id' => $milk->id,
        'movement_type' => 'transfer_in', 'quantity' => '4.000',
    ]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'inventory.transfer.created']);
});

it('transfers multiple lines in one move', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Sugar']);
    seedBranchStock($from->id, $milk->id, '10.000');
    seedBranchStock($from->id, $sugar->id, '5.000');

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '3.000'],
            ['ingredient_uuid' => $sugar->uuid, 'quantity' => '2.000'],
        ],
    ])->assertCreated();

    expect(balance($to->id, $milk->id))->toBe('3.000');
    expect(balance($to->id, $sugar->id))->toBe('2.000');
});

it('refuses to transfer more than the source holds (no negative stock)', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    seedBranchStock($from->id, $milk->id, '3.000');

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '5.000']],
    ])->assertStatus(422);

    // Nothing moved — atomic rollback.
    expect(balance($from->id, $milk->id))->toBe('3.000');
    expect(DB::table('pos_branch_transfers')->count())->toBe(0);
    expect(DB::table('pos_stock_movements')->count())->toBe(0);
});

it('refuses transferring a branch into itself', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    seedBranchStock($from->id, $milk->id, '10.000');

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $from->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '1.000']],
    ])->assertStatus(422);
});

it('refuses a destination branch from another company', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    seedBranchStock($from->id, $milk->id, '10.000');

    $other = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($other, 'company')->create();

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $foreignBranch->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '1.000']],
    ])->assertStatus(422);
});

it('refuses an ingredient from another company', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();

    $other = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($other, 'company')->create();

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $foreignIngredient->uuid, 'quantity' => '1.000']],
    ])->assertStatus(422);
});

it('lists and shows transfers scoped to the company', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    seedBranchStock($from->id, $milk->id, '10.000');

    $created = $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '2.000']],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');

    $this->getJson('/api/branch-transfers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/branch-transfers/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $uuid)
        ->assertJsonPath('data.lines.0.ingredient_name', 'Milk');
});

it('404s showing a transfer from another company', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    seedBranchStock($from->id, $milk->id, '10.000');
    $uuid = $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '1.000']],
    ])->json('data.uuid');

    // New tenant can't see it.
    makeMerchantActor();
    $this->getJson("/api/branch-transfers/{$uuid}")->assertNotFound();
});

it('forbids a read-only role from creating a transfer', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value); // inventory.view, not manage
    $from = $ctx['branch'];
    $to = Branch::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    seedBranchStock($from->id, $milk->id, '10.000');

    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '1.000']],
    ])->assertForbidden();

    // ...but can read the (empty) list.
    $this->getJson('/api/branch-transfers')->assertOk();
});

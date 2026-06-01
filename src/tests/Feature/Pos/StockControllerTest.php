<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5a StockController + WriteStockMovementAction
 * invariants.
 *
 * Covers:
 *   - LIST: stock balances at a branch with eager-loaded
 *     ingredient + health_level computed correctly.
 *   - Cross-tenant 404 on the branch route param.
 *   - ADJUST: creates ledger row + updates balance + audit;
 *     note required; zero rejected; cross-tenant ingredient → 422;
 *     cross-tenant branch → 404.
 *   - RESTOCK: positive only; optional supplier captured in
 *     reference_type/id; cross-tenant supplier → 422; unit_cost
 *     override vs default.
 *   - MOVEMENTS: paginated; ingredient + type filters; bogus
 *     ingredient uuid silently returns zero (no leak).
 *   - INVARIANT: balance == SUM(movements) after a sequence of
 *     mixed adjustments and restocks.
 *   - Permission gates throughout.
 */

use App\Enums\MerchantRole;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== LIST STOCK ===================

it('lists per-branch stock with ingredient + computed health_level', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Milk', 'min_stock_threshold' => '5.000']);
    // Below threshold but >0 → low
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '2.000']);

    // Cross-tenant — must not leak.
    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $otherIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();
    BranchStock::factory()
        ->for($otherBranch, 'branch')
        ->for($otherIngredient, 'ingredient')
        ->create(['quantity' => '1.000']);

    $response = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['ingredient']['name'])->toBe('Milk');
    expect($data[0]['quantity'])->toBe('2.000');
    expect($data[0]['health_level'])->toBe('low');
});

it('returns 404 when listing stock of a branch owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();

    $this->getJson("/api/branches/{$foreignBranch->uuid}/stock")->assertNotFound();
});

// =================== ADJUST ===================

it('records an adjustment that writes a ledger row + upserts the branch balance + audits', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // No balance row exists yet — adjust should create one.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '5.000',
        'note' => 'Opening stock during pilot setup',
    ])->assertCreated();

    // Ledger row written.
    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect($movement->movement_type)->toBe(StockMovementType::Adjustment);
    expect((string) $movement->quantity)->toBe('5.000');
    expect($movement->note)->toBe('Opening stock during pilot setup');

    // Balance row created with matching quantity.
    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('5.000');

    // Audit row.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.movement.created',
        'auditable_id' => $movement->id,
        'branch_id' => $ctx['branch']->id,
    ]);
});

it('rejects an adjustment with zero quantity', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '0',
        'note' => 'No change',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('zero');
});

it('rejects an adjustment with a missing note', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '1.000',
        // missing note
    ])->assertStatus(422)->assertJsonValidationErrors(['note']);
});

it('returns 422 when adjusting with a cross-tenant ingredient uuid', function (): void {
    $ctx = makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    // The controller's resolveIngredient returns null for
    // cross-tenant uuid → 422 "Ingredient not found".
    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $foreignIngredient->uuid,
        'signed_quantity' => '5.000',
        'note' => 'Hijack',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Ingredient');
});

it('returns 404 when adjusting at a branch owned by another company', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();

    $this->postJson("/api/branches/{$foreignBranch->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '5.000',
        'note' => 'Hijack',
    ])->assertNotFound();
});

// =================== RESTOCK ===================

it('records a restock as a positive inflow + balance + audit', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['default_unit_cost' => '2.500']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '10.000',
    ])->assertCreated();

    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect($movement->movement_type)->toBe(StockMovementType::Restock);
    expect((string) $movement->quantity)->toBe('10.000');
    // No unit_cost override → uses ingredient default.
    expect((string) $movement->unit_cost_at_time)->toBe('2.500');

    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('10.000');
});

it('records an ingredient-purchase expense for a restock', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['default_unit_cost' => '1.500', 'name' => 'Milk']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '10.000',
        'unit_cost' => '1.500',
    ])->assertCreated();

    // 10 x 1.5 = 15.000, auto-logged as an 'ingredients' expense.
    $this->assertDatabaseHas('pos_expenses', [
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'category' => 'ingredients',
        'amount' => '15.000',
        'status' => 'recorded',
    ]);
});

it('does not create an expense for a zero-cost restock', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['default_unit_cost' => '0.000']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '10.000',
    ])->assertCreated();

    expect(\App\Models\Expense::query()->count())->toBe(0);
});

it('rejects a restock with negative quantity', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '-1.000',
    ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
});

it('captures the supplier reference on a restock', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $supplier = Supplier::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '5.000',
        'supplier_uuid' => $supplier->uuid,
    ])->assertCreated();

    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->firstOrFail();
    expect($movement->reference_type)->toBe(Supplier::class);
    expect((int) $movement->reference_id)->toBe($supplier->id);
});

it('returns 422 when restock supplier_uuid is from another company', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $otherCompany = Company::factory()->create();
    $foreignSupplier = Supplier::factory()->for($otherCompany, 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '5.000',
        'supplier_uuid' => $foreignSupplier->uuid,
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Supplier');
});

it('honours a unit_cost override on a restock', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['default_unit_cost' => '2.500']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '5.000',
        'unit_cost' => '3.000',  // override
    ])->assertCreated();

    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->firstOrFail();
    expect((string) $movement->unit_cost_at_time)->toBe('3.000');
});

// =================== MOVEMENT LEDGER ===================

it('returns paginated movements with ingredient + type filters', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Beans']);

    // Mix some movements.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->count(3)->create();
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')
        ->adjustment('-1.000')->create();

    // No filter → all 4.
    $all = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock-movements")->assertOk();
    expect($all->json('data'))->toHaveCount(4);

    // Filter by ingredient → 3.
    $byIngredient = $this->getJson(
        "/api/branches/{$ctx['branch']->uuid}/stock-movements?ingredient={$milk->uuid}",
    )->assertOk();
    expect($byIngredient->json('data'))->toHaveCount(3);

    // Filter by type → 1 (the adjustment).
    $byType = $this->getJson(
        "/api/branches/{$ctx['branch']->uuid}/stock-movements?type=adjustment",
    )->assertOk();
    expect($byType->json('data'))->toHaveCount(1);
});

it('returns zero movements when ingredient filter uuid is cross-tenant', function (): void {
    $ctx = makeMerchantActor();
    StockMovement::factory()->for($ctx['branch'], 'branch')->count(2)->create();
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    // -1 sentinel guarantees zero results — no info leak.
    $response = $this->getJson(
        "/api/branches/{$ctx['branch']->uuid}/stock-movements?ingredient={$foreignIngredient->uuid}",
    )->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

// =================== INVARIANT TEST ===================

it('keeps branch_stock.quantity in lock-step with SUM(movements) across a mixed sequence', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['default_unit_cost' => '1.000']);

    // Sequence: +10 (initial via adjust) → +5 (restock) → -2 (adjust) → +3 (restock).
    // Expected balance: 10 + 5 - 2 + 3 = 16.

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '10.000',
        'note' => 'Opening',
    ])->assertCreated();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '5.000',
    ])->assertCreated();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '-2.000',
        'note' => 'Spillage during count',
    ])->assertCreated();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '3.000',
    ])->assertCreated();

    // Balance row says 16.000.
    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('16.000');

    // SUM(movements) also says 16.000.
    $sum = (string) DB::table('pos_stock_movements')
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->sum('quantity');
    expect((float) $sum)->toBe(16.0);
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list branch stock but forbids adjust + restock', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '1.000',
        'note' => 'sneaky',
    ])->assertForbidden();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
    ])->assertForbidden();
});

it('lets an InventoryManager adjust + restock', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ingredient->uuid,
        'signed_quantity' => '5.000',
        'note' => 'Opening',
    ])->assertCreated();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '2.000',
    ])->assertCreated();
});

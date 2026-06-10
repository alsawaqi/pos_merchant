<?php

declare(strict_types=1);

/**
 * Feature tests for Phase A RecordPurchaseAction + the purchase
 * endpoints (Additions doc §2.4 — purchase flow).
 *
 * The three entry shapes:
 *   FIXED  pieces only          → pieces × ingredient.units_per_piece
 *   LOOSE  pieces + units       → units authoritative; batch ratio
 *                                 (units ÷ pieces) becomes the
 *                                 ingredient's new units_per_piece
 *                                 (LAST BATCH WINS)
 *   PLAIN  units only           → classic restock w/ batch record
 *
 * Every purchase: batch row + restock movement (+balance) + expense
 * for EXACTLY total_paid + default_unit_cost = total ÷ units + audit.
 */

use App\Enums\MerchantRole;
use App\Enums\StockMovementType;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientPurchase;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== FIXED-RATIO PURCHASE ===================

it('records a fixed-ratio piece purchase: movement, balance, batch row, cost update, exact expense', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Whole Milk',
        'unit' => 'l',
        'piece_unit_label' => 'bottle',
        'units_per_piece' => '1.0000',
        'default_unit_cost' => '0.000',
    ]);

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $milk->uuid,
        'pieces' => 5,
        'total_paid' => '2.500',
    ])->assertCreated();

    // Batch row: 5 bottles × 1 L = 5 L, unit cost 2.500 ÷ 5 = 0.5.
    $purchase = IngredientPurchase::query()->where('ingredient_id', $milk->id)->firstOrFail();
    expect((string) $purchase->pieces_received)->toBe('5.000');
    expect((string) $purchase->units_received)->toBe('5.000');
    expect((string) $purchase->total_paid)->toBe('2.500');
    expect((string) $purchase->unit_cost)->toBe('0.500000');
    expect((string) $purchase->units_per_piece_at_purchase)->toBe('1.0000');
    expect($purchase->is_loose)->toBeFalse();

    // Restock movement references the purchase + moved the balance.
    $movement = StockMovement::query()
        ->where('ingredient_id', $milk->id)
        ->where('movement_type', StockMovementType::Restock->value)
        ->firstOrFail();
    expect((string) $movement->quantity)->toBe('5.000');
    expect($movement->reference_type)->toBe(IngredientPurchase::class);
    expect((int) $movement->reference_id)->toBe($purchase->id);
    expect((int) $purchase->stock_movement_id)->toBe($movement->id);

    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $milk->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('5.000');

    // §2.4 step 4 — cost_per_unit updated from the batch.
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.500');

    // Expense = EXACTLY the money paid (not qty × rounded cost).
    $this->assertDatabaseHas('pos_expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => 'ingredients',
        'amount' => '2.500',
    ]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.purchase.recorded',
        'auditable_id' => $purchase->id,
        'branch_id' => $ctx['branch']->id,
    ]);

    expect($response->json('data.units_received'))->toBe('5.000');
    expect($response->json('data.is_loose'))->toBeFalse();
});

// =================== LOOSE BATCH ===================

it('records a loose batch (pieces + weighed units) and the batch ratio becomes the new default — last batch wins', function (): void {
    $ctx = makeMerchantActor();
    // The doc's worked example: 7 tomatoes weighing 10 kg.
    $tomato = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Tomato',
        'unit' => 'g',
        'piece_unit_label' => 'piece',
        'units_per_piece' => '1000.0000', // stale ratio from an older batch
        'default_unit_cost' => '0.000',
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $tomato->uuid,
        'pieces' => 7,
        'units' => 10000,
        'total_paid' => '10.500',
    ])->assertCreated();

    $purchase = IngredientPurchase::query()->where('ingredient_id', $tomato->id)->firstOrFail();
    expect($purchase->is_loose)->toBeTrue();
    expect((string) $purchase->units_received)->toBe('10000.000');
    // 10000 ÷ 7 = 1428.5714…
    expect((string) $purchase->units_per_piece_at_purchase)->toBe('1428.5714');
    // unit cost 10.500 ÷ 10000 = 0.00105 — survives at 6dp.
    expect((string) $purchase->unit_cost)->toBe('0.001050');

    // LAST BATCH WINS on the ingredient.
    $fresh = $tomato->fresh();
    expect((string) $fresh->units_per_piece)->toBe('1428.5714');
    // …while default_unit_cost is the (12,3) rounding of the batch cost.
    expect((string) $fresh->default_unit_cost)->toBe('0.001');

    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $tomato->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('10000.000');
});

// =================== PLAIN UNITS PURCHASE ===================

it('records a units-only purchase for a non-piece ingredient', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Flour',
        'unit' => 'kg',
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $flour->uuid,
        'units' => '25',
        'total_paid' => '7.500',
    ])->assertCreated();

    $purchase = IngredientPurchase::query()->where('ingredient_id', $flour->id)->firstOrFail();
    expect($purchase->pieces_received)->toBeNull();
    expect((string) $purchase->units_received)->toBe('25.000');
    expect($purchase->units_per_piece_at_purchase)->toBeNull();
    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.300');
});

// =================== VALIDATION ===================

it('rejects a pieces-only purchase when the ingredient has no piece ratio', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Flour',
        'unit' => 'kg',
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $flour->uuid,
        'pieces' => 3,
        'total_paid' => '1.000',
    ])->assertUnprocessable();

    expect(IngredientPurchase::query()->count())->toBe(0);
    expect(StockMovement::query()->count())->toBe(0);
});

it('treats a pieces-only purchase of a base-unit=piece ingredient as ratio 1', function (): void {
    $ctx = makeMerchantActor();
    $eggs = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Eggs',
        'unit' => 'piece',
        'allow_fractional_pieces' => false,
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $eggs->uuid,
        'pieces' => 30,
        'total_paid' => '1.200',
    ])->assertCreated();

    $purchase = IngredientPurchase::query()->where('ingredient_id', $eggs->id)->firstOrFail();
    expect((string) $purchase->units_received)->toBe('30.000');
});

it('rejects fractional pieces when allow_fractional_pieces is false', function (): void {
    $ctx = makeMerchantActor();
    $eggs = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Eggs',
        'unit' => 'piece',
        'allow_fractional_pieces' => false,
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $eggs->uuid,
        'pieces' => 4.7,
        'total_paid' => '0.200',
    ])->assertUnprocessable();
});

it('rejects a purchase with neither pieces nor units', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $milk->uuid,
        'total_paid' => '1.000',
    ])->assertUnprocessable();
});

// =================== TENANCY + GATES ===================

it('refuses a cross-tenant ingredient (422) and a cross-tenant branch (404)', function (): void {
    $ctx = makeMerchantActor();
    $other = makeMerchantActor();
    // Re-pin OUR tenant (makeMerchantActor pins the latest one).
    app(\App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);

    $foreignIngredient = Ingredient::factory()->for($other['company'], 'company')->create(['name' => 'Foreign']);
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $foreignIngredient->uuid,
        'units' => 1,
        'total_paid' => '1.000',
    ])->assertUnprocessable();

    $mine = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Mine']);
    $this->postJson("/api/branches/{$other['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $mine->uuid,
        'units' => 1,
        'total_paid' => '1.000',
    ])->assertNotFound();
});

it('blocks purchase for a Viewer (no inventory.manage)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $milk->uuid,
        'units' => 1,
        'total_paid' => '1.000',
    ])->assertForbidden();
});

// =================== HISTORY ===================

it('lists an ingredient purchase history newest-first', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk',
        'unit' => 'l',
        'piece_unit_label' => 'bottle',
        'units_per_piece' => '1.0000',
    ]);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $milk->uuid, 'pieces' => 2, 'total_paid' => '1.000',
    ])->assertCreated();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/purchase", [
        'ingredient_uuid' => $milk->uuid, 'pieces' => 3, 'total_paid' => '1.600',
    ])->assertCreated();

    $response = $this->getJson("/api/ingredients/{$milk->uuid}/purchases")->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('data.0.pieces_received'))->toBe('3.000');
});

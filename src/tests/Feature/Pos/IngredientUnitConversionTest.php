<?php

declare(strict_types=1);

/**
 * v2 #13 (Phase 2) — entered quantities are converted to the ingredient's BASE
 * unit at EVERY entry flow (restock / adjust / transfer / waste / restock-request
 * / recipe), so storage stays base everywhere (device + pos_api unchanged).
 *
 * Each flow: ingredient base = g, alt unit "kg" factor 1000. Entering in "kg"
 * must persist the base-unit amount; an unknown unit is a clean 422.
 */

use App\Models\BranchStock;
use App\Models\BranchTransferLine;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\RestockRequestLine;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Ingredient (base = g) with a "kg" alt unit (factor 1000). */
function gramIngredientWithKg(object $company): Ingredient
{
    $ing = Ingredient::factory()->for($company, 'company')->create([
        'unit' => 'g', 'default_unit_cost' => '0.010',
    ]);
    IngredientAltUnit::query()->create([
        'company_id' => $company->id, 'ingredient_id' => $ing->id, 'name' => 'kg', 'factor' => '1000',
    ]);

    return $ing;
}

it('restock converts kg → base grams', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ing->uuid, 'quantity' => '2', 'unit' => 'kg',
    ])->assertCreated();

    expect((string) StockMovement::query()->where('ingredient_id', $ing->id)->value('quantity'))->toBe('2000.000');
    expect((string) BranchStock::query()->where('ingredient_id', $ing->id)->value('quantity'))->toBe('2000.000');
});

it('restock with no unit stays in base', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ing->uuid, 'quantity' => '500',
    ])->assertCreated();

    expect((string) BranchStock::query()->where('ingredient_id', $ing->id)->value('quantity'))->toBe('500.000');
});

it('restock rejects an unknown unit with 422', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/restock", [
        'ingredient_uuid' => $ing->uuid, 'quantity' => '2', 'unit' => 'lb',
    ])->assertStatus(422);

    expect(StockMovement::query()->where('ingredient_id', $ing->id)->exists())->toBeFalse();
});

it('adjust converts a signed kg delta → base grams', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $ing->uuid, 'signed_quantity' => '-1.5', 'unit' => 'kg', 'note' => 'spillage count',
    ])->assertCreated();

    expect((string) StockMovement::query()->where('ingredient_id', $ing->id)->value('quantity'))->toBe('-1500.000');
});

it('transfer converts kg → base grams (line + balances)', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);
    $dest = \App\Models\Branch::factory()->for($ctx['company'], 'company')->create();
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($ing, 'ingredient')->create(['quantity' => '5000.000']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/transfers", [
        'to_branch_uuid' => $dest->uuid,
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity' => '2', 'unit' => 'kg']],
    ])->assertCreated();

    $line = BranchTransferLine::query()->where('ingredient_id', $ing->id)->firstOrFail();
    expect((string) $line->quantity)->toBe('2000.000');
    expect($line->unit_at_set?->value ?? $line->unit_at_set)->toBe('g'); // label stays base
    expect((string) BranchStock::query()->where(['branch_id' => $ctx['branch']->id, 'ingredient_id' => $ing->id])->value('quantity'))->toBe('3000.000');
    expect((string) BranchStock::query()->where(['branch_id' => $dest->id, 'ingredient_id' => $ing->id])->value('quantity'))->toBe('2000.000');
});

it('waste converts kg → base grams', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($ing, 'ingredient')->create(['quantity' => '5000.000']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ing->uuid, 'quantity' => '2', 'unit' => 'kg', 'reason' => 'spoiled',
    ])->assertCreated();

    $waste = WasteRecord::query()->where('ingredient_id', $ing->id)->firstOrFail();
    expect((string) $waste->quantity)->toBe('2000.000');
    expect((string) BranchStock::query()->where('ingredient_id', $ing->id)->value('quantity'))->toBe('3000.000');
});

it('restock request stores the requested amount in base', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '2', 'unit' => 'kg']],
    ])->assertCreated();

    $line = RestockRequestLine::query()->where('ingredient_id', $ing->id)->firstOrFail();
    expect((string) $line->quantity_requested)->toBe('2000.000');
});

it('recipe stores consumption in base grams (device contract stays base)', function (): void {
    $ctx = makeMerchantActor();
    $ing = gramIngredientWithKg($ctx['company']);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity' => '0.25', 'unit' => 'kg']],
    ])->assertOk();

    $line = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $line->quantity)->toBe('250.000'); // 0.25 kg → 250 g
    expect($line->unit_at_set?->value ?? $line->unit_at_set)->toBe('g');
});

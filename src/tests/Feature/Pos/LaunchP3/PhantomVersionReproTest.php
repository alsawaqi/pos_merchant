<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, L7 — no phantom recipe version.
 *
 * A line typed "2 box" (box = 12 cups) reopens as "24 piece" once the box is
 * re-sized or deleted ({@see RecipeQuantity::display()}). Sending that
 * untouched line back used to count as a change: a new version and audit row
 * "2 box → 24 piece" blamed on whoever saved next (a price change, say), with
 * no real change. The stored line is now normalised the same way before the
 * comparison — for product recipes, add-on stock usage and prep recipes.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\IngredientAltUnit;
use App\Models\Product;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** Cups (piece) with an extra unit box = 12. */
function l7Cups(array $ctx): array
{
    $cups = p3Ingredient($ctx['company'], 'Cups', 'piece', '0.010');
    $box = IngredientAltUnit::query()->create(['company_id' => $ctx['company']->id, 'ingredient_id' => $cups->id, 'name' => 'box', 'factor' => '12']);

    return [$cups, $box];
}

function l7Audits(string $event): int
{
    return DB::table('pos_audit_logs')->where('event', $event)->count();
}

it('writes no version or audit row when a reopened line is re-saved untouched after its unit was re-sized or deleted', function (string $change): void {
    $ctx = makeMerchantActor();
    [$cups, $box] = l7Cups($ctx);
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee box', 'stock_mode' => 'ingredient']);
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '2', 'unit' => 'box']],
    ])->assertOk();
    $versions = ProductRecipeVersion::query()->count();
    $audits = l7Audits('catalogue.product.recipe_updated');

    $change === 'resized' ? $box->forceFill(['factor' => '10'])->save() : $box->delete();

    // The editor reopens the line in its base unit and sends exactly that back.
    $line = $this->getJson("/api/products/{$product->uuid}")->json('data.recipe_lines.0');
    expect($line['entered_unit'])->toBeNull()->and($line['quantity'])->toBe('24.000');
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '24', 'unit' => null]],
    ])->assertOk();

    expect(ProductRecipeVersion::query()->count())->toBe($versions)
        ->and(l7Audits('catalogue.product.recipe_updated'))->toBe($audits);

    // A real change is still recorded — and its "before" reads as what the editor showed.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '20', 'unit' => null]],
    ])->assertOk();
    $history = $this->getJson("/api/products/{$product->uuid}/recipe-history")->assertOk()->json('data.versions.0.changes.0');
    expect($history['before'])->toBe('24 piece')->and($history['after'])->toBe('20 piece');
})->with(['resized', 'deleted']);

it('keeps an add-on option re-save a no-op after its extra unit was re-sized', function (): void {
    $ctx = makeMerchantActor();
    [$cups, $box] = l7Cups($ctx);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Packs']);
    $uuid = $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Party pack',
        'price_delta' => '1.000',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $cups->uuid, 'direction' => 'add', 'quantity' => '2', 'unit' => 'box']],
    ])->assertCreated()->json('data.uuid');
    $audits = l7Audits('catalogue.addon.consumption_updated');

    $box->forceFill(['factor' => '10'])->save();
    $this->patchJson("/api/addons/{$uuid}", [
        'name' => 'Party pack',
        'price_delta' => '1.250',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $cups->uuid, 'direction' => 'add', 'quantity' => '24', 'unit' => null]],
    ])->assertOk();

    expect(l7Audits('catalogue.addon.consumption_updated'))->toBe($audits)
        ->and((string) AddOn::query()->where('uuid', $uuid)->value('price_delta'))->toBe('1.250');
});

it('keeps a prep recipe re-save a no-op after its extra unit was re-sized', function (): void {
    $ctx = makeMerchantActor();
    [$cups, $box] = l7Cups($ctx);
    $uuid = $this->postJson('/api/prep-items', [
        'name' => 'Cup kit',
        'unit' => 'piece',
        'prep_yield_quantity' => '24',
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '2', 'unit' => 'box']],
    ])->assertCreated()->json('data.uuid');
    $audits = l7Audits('catalogue.prep_item.recipe_updated');

    $box->forceFill(['factor' => '10'])->save();
    $line = $this->getJson("/api/prep-items/{$uuid}")->json('data.lines.0');
    expect($line['entered_unit'])->toBeNull();
    $this->patchJson("/api/prep-items/{$uuid}", [
        'name' => 'Cup kit',
        'prep_yield_quantity' => '24',
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '24', 'unit' => null]],
    ])->assertOk();

    expect(l7Audits('catalogue.prep_item.recipe_updated'))->toBe($audits);
});

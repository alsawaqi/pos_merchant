<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, M1-a — the save-time precision guard.
 *
 * The device copy explodes a prep item per ONE unit sold and rounds each raw
 * line to 4 decimals of its base unit. 15 ml of a 1000 ml saffron syrup made
 * with 2 g of a KG-based saffron is 0.00003 kg per latte: 0 at 4 decimals, so
 * 100 lattes would deduct no saffron and cost it at 0. Product recipes,
 * add-on stock usage and prep edits (re-checking every user of the prep) now
 * refuse that with the P3-1 message, naming the ingredient and pointing to a
 * g / ml ingredient.
 */

use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\IngredientRecipe;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** Saffron (kg) + sugar (kg) in a 1000 ml syrup: 2 g saffron, 500 g sugar per batch. */
function m1Syrup(array $ctx, string $saffronUnit = 'kg'): array
{
    $saffron = $saffronUnit === 'kg'
        ? p3Ingredient($ctx['company'], 'Saffron', 'kg', '2000.000')
        : p3Ingredient($ctx['company'], 'Saffron', 'g', '2.000');
    $sugar = p3Ingredient($ctx['company'], 'Sugar', 'kg', '0.400');
    $syrup = p3Prep($ctx['company'], 'Saffron syrup', 'ml', '1000', [
        [$saffron, $saffronUnit === 'kg' ? '0.002' : '2'],
        [$sugar, '0.5'],
    ]);

    return ['saffron' => $saffron, 'sugar' => $sugar, 'syrup' => $syrup];
}

it('refuses a latte whose saffron would round to 0 per cup, naming the ingredient and pointing to g', function (): void {
    $ctx = makeMerchantActor();
    $f = m1Syrup($ctx);
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Saffron latte', 'stock_mode' => 'ingredient']);

    $response = $this->putJson("/api/products/{$latte->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $f['syrup']->uuid, 'quantity' => '15']],
    ])->assertStatus(422);

    expect($response->json('message'))
        ->toStartWith('Saffron: each "Saffron latte" uses 0.00003 kg of it through Saffron syrup')
        ->toContain('too small to record')
        ->toContain('Keep Saffron in g');
    expect(ProductRecipe::query()->where('product_id', $latte->id)->count())->toBe(0)
        ->and(ProductRecipeVersion::query()->count())->toBe(0);
});

it('refuses an amount that would be doubled by the rounding (0.00005 kg stored as 0.0001)', function (): void {
    $ctx = makeMerchantActor();
    $saffron = p3Ingredient($ctx['company'], 'Saffron', 'kg', '2000.000');
    $kahwa = p3Prep($ctx['company'], 'Kahwa base', 'ml', '1000', [[$saffron, '0.001']]);
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Kahwa', 'stock_mode' => 'ingredient']);

    $response = $this->putJson("/api/products/{$cup->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $kahwa->uuid, 'quantity' => '50']],
    ])->assertStatus(422);

    expect($response->json('message'))
        ->toContain('Saffron: each "Kahwa" uses 0.00005 kg of it through Kahwa base, which cannot be recorded accurately')
        ->toContain('it would become 0.0001 kg');
});

it('accepts the same latte when saffron is kept in g, and a dose that records accurately', function (): void {
    $ctx = makeMerchantActor();
    $f = m1Syrup($ctx, 'g');
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Saffron latte', 'stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$latte->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $f['syrup']->uuid, 'quantity' => '15']],
    ])->assertOk();

    // A kg saffron is fine too once one cup really uses at least 0.0001 kg exactly.
    $ctx2 = makeMerchantActor();
    $g = m1Syrup($ctx2);
    $big = Product::factory()->for($ctx2['company'], 'company')->create(['name' => 'Big latte', 'stock_mode' => 'ingredient']);
    $this->putJson("/api/products/{$big->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $g['syrup']->uuid, 'quantity' => '150']],
    ])->assertOk();
    expect(ProductRecipe::query()->where('product_id', $big->id)->count())->toBe(1);
});

it('refuses an add-on option whose saffron would round to 0 per selection', function (): void {
    $ctx = makeMerchantActor();
    $f = m1Syrup($ctx);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Syrups']);

    $response = $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Saffron shot',
        'price_delta' => '0.300',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $f['syrup']->uuid, 'direction' => 'add', 'quantity' => '15']],
    ])->assertStatus(422);

    expect($response->json('message'))->toStartWith('Saffron: each "Saffron shot" option uses 0.00003 kg of it through Saffron syrup');
    expect(AddOnConsumption::query()->count())->toBe(0);
});

it('refuses a prep edit that would make a dish using it (directly or through another prep) imprecise', function (): void {
    $ctx = makeMerchantActor();
    $f = m1Syrup($ctx);
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Saffron latte', 'stock_mode' => 'ingredient']);
    // 150 ml of syrup = 0.0003 kg saffron per latte: accepted today.
    $this->putJson("/api/products/{$latte->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $f['syrup']->uuid, 'quantity' => '150']],
    ])->assertOk();

    // A bigger batch from the same 2 g would leave 0.00003 kg per latte.
    $response = $this->patchJson("/api/prep-items/{$f['syrup']->uuid}", ['prep_yield_quantity' => '10000'])->assertStatus(422);
    expect($response->json('message'))->toStartWith('Saffron: each "Saffron latte" uses 0.00003 kg of it through Saffron syrup');
    expect((float) DB::table('pos_ingredients')->where('id', $f['syrup']->id)->value('prep_yield_quantity'))->toBe(1000.0);

    // The same through a second prep item: a cake glaze made of the syrup
    // (200 ml of glaze = 200 ml of syrup = 0.0004 kg saffron per cake).
    $latte->recipeLines()->delete();
    $glaze = p3Prep($ctx['company'], 'Glaze', 'ml', '100', [[$f['syrup'], '100']]);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Saffron cake', 'stock_mode' => 'cooked']);
    $this->putJson("/api/products/{$cake->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $glaze->uuid, 'quantity' => '200']],
    ])->assertOk();

    // A tenth of a gram per syrup batch would leave 0.00002 kg per cake.
    $response = $this->patchJson("/api/prep-items/{$f['syrup']->uuid}", [
        'lines' => [
            ['ingredient_uuid' => $f['saffron']->uuid, 'quantity' => '0.1', 'unit' => 'g'],
            ['ingredient_uuid' => $f['sugar']->uuid, 'quantity' => '0.5'],
        ],
    ])->assertStatus(422);
    expect($response->json('message'))->toStartWith('Saffron: each "Saffron cake" uses 0.00002 kg of it through Glaze');
    expect((float) IngredientRecipe::query()->where('prep_ingredient_id', $f['syrup']->id)->where('ingredient_id', $f['saffron']->id)->value('quantity'))->toBe(0.002);

    // A change every user still records accurately is saved.
    $this->patchJson("/api/prep-items/{$f['syrup']->uuid}", [
        'lines' => [
            ['ingredient_uuid' => $f['saffron']->uuid, 'quantity' => '1', 'unit' => 'g'],
            ['ingredient_uuid' => $f['sugar']->uuid, 'quantity' => '0.5'],
        ],
    ])->assertOk();
});

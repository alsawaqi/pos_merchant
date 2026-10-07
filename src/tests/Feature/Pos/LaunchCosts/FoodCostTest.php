<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens add-on, Part A (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester call 2): one company target food cost % (default 30) and a per-
 * product override; food cost % = today's recipe / composition cost ÷ the
 * in-store price EXCLUDING VAT (the company's tax mode); combos and meals
 * sum their items (upgrades not taken, choices at their cheapest); no cost =
 * "no recipe". Shown on the product list and page (red flag over target),
 * in the Recipe & Cost report ("Target", "Over by") and on a dashboard card
 * linked to the filtered list. Costs need reports.view.
 *
 *   Burger 2.000 = bun 0.050 + 150 g beef × 0.004 = 0.650 → 32.5% (over 30 by 2.5)
 *   Wrap 1.000 = 500 g flour × 0.0004 (all) + plate 0.100 (dine-in) / box
 *     0.300 (others) → dearest type 0.500 → 50%
 *   Cola 0.500, bought in at cost price 0.150 → 30% (not over: equal)
 *   Water 0.300, no recipe, no cost price → "no recipe"
 *   Cheese burger 2.500 = 0.650 → 26%
 *   Box 3.000 = Burger (upgrade Cheese burger not taken) + 2 drinks at the
 *     cheapest costed (Cola 0.150; Water has none) → 0.950 → 31.7%
 *   Meal +1.000 on Burgers, with Fries (cost price 0.200): "Burger meal"
 *     3.000, cost 0.850 → 28.3%
 */

use App\Enums\MerchantPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** @return array<string, mixed> */
function lcMenu(array $ctx): array
{
    $c = $ctx['company'];
    $k = [
        'bun' => p3Ingredient($c, 'Bun', 'piece', '0.050000'),
        'beef' => p3Ingredient($c, 'Beef', 'g', '0.004000'),
        'flour' => p3Ingredient($c, 'Flour', 'g', '0.000400'),
        'plate' => p3Ingredient($c, 'Plate wrap', 'piece', '0.100000'),
        'box' => p3Ingredient($c, 'To-go box', 'piece', '0.300000'),
        'burgers' => lcCategory($c, 'Burgers'),
        'drinks' => lcCategory($c, 'Drinks'),
    ];
    $k['burger'] = p4Product($c, 'Burger', '2.000', ['stock_mode' => 'ingredient', 'category_id' => $k['burgers']]);
    lcRecipe($k['burger'], [[$k['bun'], '1'], [$k['beef'], '150']]);
    $k['cheeseburger'] = p4Product($c, 'Cheese burger', '2.500', ['stock_mode' => 'ingredient']);
    lcRecipe($k['cheeseburger'], [[$k['bun'], '1'], [$k['beef'], '150']]);
    $k['wrap'] = p4Product($c, 'Wrap', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($k['wrap'], [[$k['flour'], '500'], [$k['plate'], '1', 1], [$k['box'], '1', 14]]);
    $k['cola'] = p4Product($c, 'Cola', '0.500', ['category_id' => $k['drinks'], 'stock_mode' => 'unit', 'cost_price' => '0.150']);
    $k['water'] = p4Product($c, 'Water', '0.300', ['category_id' => $k['drinks']]);
    $k['fries'] = p4Product($c, 'Fries', '0.700', ['cost_price' => '0.200']);
    $k['combo'] = p4Product($c, 'Box', '3.000', ['product_type' => 'combo']);
    lcLine($c, ['combo_product_id' => $k['combo']->id], ['kind' => 'fixed', 'product_id' => $k['burger']->id, 'quantity' => 1], [$k['cheeseburger']->id => '0.500']);
    lcLine($c, ['combo_product_id' => $k['combo']->id], ['kind' => 'choice', 'category_id' => $k['drinks'], 'pick_count' => 2, 'name' => 'Drinks', 'name_ar' => 'المشروبات']);
    $k['meal'] = lcMeal($c, 'meal', '1.000', [$k['burgers']]);
    lcLine($c, ['meal_id' => $k['meal']], ['kind' => 'fixed', 'product_id' => $k['fries']->id, 'quantity' => 1]);

    return $k;
}

/** @return array<string, array<string, mixed>> food-cost rows by name */
function lcFoodCosts(): array
{
    return collect(test()->getJson('/api/food-costs')->assertOk()->json('data'))->keyBy('name')->all();
}

it('works out each dish\'s food cost % against the company target, combos and meals included', function (): void {
    $ctx = makeMerchantActor();
    lcMenu($ctx);

    $rows = lcFoodCosts();
    $pick = static fn (array $r): array => [$r['status'], $r['cost_baisas'], $r['net_price_baisas'], $r['food_cost_pct'], $r['target_pct'], $r['over_target'], $r['over_by_pct']];
    expect($pick($rows['Burger']))->toEqual(['ok', 650, 2000, 32.5, 30.0, true, 2.5])
        ->and($pick($rows['Wrap']))->toEqual(['ok', 500, 1000, 50.0, 30.0, true, 20.0])
        ->and($pick($rows['Cola']))->toEqual(['ok', 150, 500, 30.0, 30.0, false, null])
        ->and($pick($rows['Water']))->toEqual(['no_recipe', null, 300, null, 30.0, false, null])
        ->and($pick($rows['Box']))->toEqual(['ok', 950, 3000, 31.7, 30.0, true, 1.7])
        ->and($rows['Box']['product_type'])->toBe('combo')
        ->and($pick($rows['Burger meal']))->toEqual(['ok', 850, 3000, 28.3, 30.0, false, null])
        ->and($rows['Burger meal']['type'])->toBe('meal');
    // The filtered list: only the dishes over target.
    expect(array_column($this->getJson('/api/food-costs?over=1')->json('data'), 'name'))->toBe(['Box', 'Burger', 'Wrap']);

    // The dashboard card counts them.
    expect($this->getJson('/api/dashboard/summary')->assertOk()->json('data.dishes_over_target'))
        ->toEqual(['count' => 3, 'costed' => 7, 'incomplete' => 0, 'no_recipe' => 1, 'target_percent' => 30]);
});

it('divides by the price excluding VAT when the merchant\'s prices include it, and by the price when they do not', function (): void {
    $ctx = makeMerchantActor();
    $k = lcMenu($ctx);
    p4RegisterVat($ctx['company']);
    DB::table('pos_taxes')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'name' => 'VAT',
        'rate_percent' => '5.00', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

    // 2.000 includes VAT 5%: round(2000 × 5 ÷ 105) = 95 → 1.905 excluding VAT.
    $burger = lcFoodCosts()['Burger'];
    expect([$burger['net_price_baisas'], $burger['food_cost_pct']])->toEqual([1905, 34.1]);

    // "Menu prices include VAT" off: VAT is added on top, the price is the net.
    DB::table('pos_company_settings')->updateOrInsert(['company_id' => $ctx['company']->id, 'key' => 'tax.prices_include_vat'], ['value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]);
    expect(lcFoodCosts()['Burger']['net_price_baisas'])->toBe(2000);
});

it('saves a dish\'s own target and a company target, and flags against the right one', function (): void {
    $ctx = makeMerchantActor();
    $k = lcMenu($ctx);

    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => '35'])->assertOk()
        ->assertJsonPath('data.target_food_cost_percent', '35.00')
        ->assertJsonPath('data.food_cost.over_target', false)
        ->assertJsonPath('data.food_cost.target_source', 'product');
    $audit = DB::table('pos_audit_logs')->where('event', 'catalogue.product.updated')->orderByDesc('id')->first();
    expect(json_decode((string) $audit->new_values, true))->toBe(['target_food_cost_percent' => '35.00']);
    // Same value written differently: no change, no audit.
    $before = DB::table('pos_audit_logs')->count();
    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => '35.0'])->assertOk();
    expect(DB::table('pos_audit_logs')->count())->toBe($before);
    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => 0])->assertUnprocessable();
    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => '100.5'])->assertUnprocessable();
    // The column keeps 2 decimals: a third is refused, never rounded; any value above 0 is a target.
    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => '28.555'])->assertUnprocessable();
    $this->putJson('/api/settings/costs', ['target_food_cost_percent' => '0.05'])->assertOk()->assertJsonPath('data.target_food_cost_percent', '0.05');

    // The company target moves every dish without its own.
    $this->putJson('/api/settings/costs', ['target_food_cost_percent' => 50])->assertOk();
    $rows = lcFoodCosts();
    expect([$rows['Wrap']['over_target'], $rows['Burger']['target_pct'], $rows['Burger']['target_source'], $rows['Wrap']['target_pct']])
        ->toEqual([false, 35.0, 'product', 50.0]);
    // Back to the company's: blank.
    $this->patchJson('/api/products/'.$k['burger']->uuid, ['target_food_cost_percent' => null])->assertOk()
        ->assertJsonPath('data.target_food_cost_percent', null)->assertJsonPath('data.food_cost.target_source', 'company');

    // A new product through the wizard, and a combo, carry their own target.
    $created = $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Salad', 'base_price' => '1.500', 'stock_mode' => 'untracked', 'target_food_cost_percent' => '25'],
        'addon_group_uuids' => [], 'owned_groups' => [], 'recipe_lines' => [], 'component_lines' => [], 'branches' => null, 'delivery_prices' => [],
    ])->assertCreated()->json('data');
    expect($created['target_food_cost_percent'])->toBe('25.00');
});

it('lists the % with a red flag on the product list and page, filters the list over target, and keeps costs to reports.view', function (): void {
    $ctx = makeMerchantActor();
    $k = lcMenu($ctx);

    $list = collect($this->getJson('/api/products?per_page=200')->assertOk()->json('data'))->keyBy('name');
    expect($list['Burger']['food_cost']['food_cost_pct'])->toEqual(32.5)
        ->and($list['Burger']['food_cost']['over_target'])->toBeTrue()
        ->and($list['Water']['food_cost']['status'])->toBe('no_recipe');
    expect(array_column($this->getJson('/api/products?food_cost=over')->assertOk()->json('data'), 'name'))->toEqualCanonicalizing(['Burger', 'Wrap', 'Box']);
    expect($this->getJson('/api/products/'.$k['combo']->uuid)->assertOk()->json('data.food_cost.cost_baisas'))->toBe(950);

    // Without reports.view: no costs, and the filter does nothing.
    lcActAs($ctx, [MerchantPermission::CatalogueView->value]);
    $list = collect($this->getJson('/api/products?food_cost=over')->assertOk()->json('data'));
    expect($list->pluck('food_cost')->unique()->all())->toBe([null])->and($list)->toHaveCount(7);
    $this->getJson('/api/food-costs')->assertForbidden();
});

it('adds "Target" and "Over by" to the Recipe & Cost report', function (): void {
    $ctx = makeMerchantActor();
    $k = lcMenu($ctx);
    $this->patchJson('/api/products/'.$k['wrap']->uuid, ['target_food_cost_percent' => '45'])->assertOk();

    $rows = collect($this->getJson('/api/reports/recipe-cost?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())->assertOk()->json('data.rows'))->keyBy('product_name');
    $pick = static fn (array $r): array => [$r['theoretical_cost'], $r['net_price'], $r['food_cost_pct'], $r['target_pct'], $r['over_target'], $r['over_by_pct']];
    expect($pick($rows['Burger']))->toEqual(['0.650', '2.000', 32.5, 30.0, true, 2.5])
        ->and($pick($rows['Wrap']))->toEqual(['0.500', '1.000', 50.0, 45.0, true, 5.0])
        ->and($rows['Wrap']['target_source'])->toBe('product');
});

<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens, Part A — fix order 2 (LAUNCH-COSTS_A_FIX_ORDER_2.md).
 *
 *   K-8   a dish costs its dearest REAL order type: recipe rows and food
 *         component rows of that type, as stock takes them (component
 *         "Used for" ticks apply in every stock mode)
 *   K-9   a component is costed at its own per-piece cost (one level, like
 *         stock): a loop gives the same costs whatever is costed first
 *   K-10  a missing cost is never reported as a complete food cost
 *   K-11  the Recipe & Cost report's cost, profit, margin and food cost %
 *         agree in one row
 *   K-12  a physical item's "may contain" survives a save that does not send it
 */

use App\Support\Costs\FoodCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function lc2Component(int $productId, int $componentId, string $qty, int $mask = 15): void
{
    DB::table('pos_product_components')->insert(['product_id' => $productId, 'component_product_id' => $componentId, 'quantity' => $qty,
        'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, array<string, mixed>> */
function lc2Rows(): array
{
    return collect(test()->getJson('/api/food-costs')->assertOk()->json('data'))->keyBy('name')->all();
}

it('K-8 costs a cooked dish with its component rows of ONE order type (2 cups, not 3)', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $flour = p3Ingredient($c, 'Flour', 'g', '0.000400');
    $cup = p4Product($c, 'Garlic sauce cup', '0.300', ['stock_mode' => 'unit', 'cost_price' => '0.100']);
    $wrap = p4Product($c, 'Wrap', '1.000', ['stock_mode' => 'cooked']);
    lcRecipe($wrap, [[$flour, '500']]); // 0.200 a piece
    lc2Component($wrap->id, $cup->id, '1', 1); // dine in: 1 cup
    lc2Component($wrap->id, $cup->id, '2', 8); // delivery: 2 cups

    // Delivery is the dearest real order: 0.200 + 2 × 0.100 = 0.400 (never 3 cups = 0.500).
    expect(lc2Rows()['Wrap']['cost_baisas'])->toBe(400);
});

it('K-8 never adds a recipe dearest on delivery to components dearest on dine in', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $flour = p3Ingredient($c, 'Flour', 'g', '0.000200');
    $box = p3Ingredient($c, 'Delivery box', 'piece', '0.300000');
    $garnish = p4Product($c, 'Garnish plate', '0.500', ['stock_mode' => 'unit', 'cost_price' => '0.250']);
    $dish = p4Product($c, 'Pasta', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($dish, [[$flour, '500'], [$box, '1', 8]]); // 0.100 everywhere + 0.300 on delivery
    lc2Component($dish->id, $garnish->id, '1', 1);       // + 0.250 on dine in

    // dine in 0.350, quick / to go 0.100, delivery 0.400 → 0.400 (not 0.400 + 0.250).
    $row = lc2Rows()['Pasta'];
    expect([$row['cost_baisas'], $row['food_cost_pct'], $row['status']])->toEqual([400, 40.0, 'ok']);
    $report = collect($this->getJson('/api/reports/recipe-cost?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()->json('data.rows'))->firstWhere('product_name', 'Pasta');
    expect([$report['theoretical_cost'], $report['theoretical_by_type']])->toBe(['0.400', ['dine_in' => '0.350', 'quick' => '0.100', 'to_go' => '0.100', 'delivery' => '0.400']]);
});

it('K-9 costs a component at its own per-piece cost, one level: a loop gives the same costs in any order', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $a = p4Product($c, 'A', '1.000', ['stock_mode' => 'unit', 'cost_price' => '0.100']);
    $b = p4Product($c, 'B', '1.000', ['stock_mode' => 'unit', 'cost_price' => '0.200']);
    lc2Component($a->id, $b->id, '1');
    lc2Component($b->id, $a->id, '1');

    $aFirst = FoodCost::load($c->id);
    $costsA = [$aFirst->product($a->id)['cost_baisas'], $aFirst->product($b->id)['cost_baisas']];
    $bFirst = FoodCost::load($c->id);
    $costsB = [$bFirst->product($b->id)['cost_baisas'], $bFirst->product($a->id)['cost_baisas']];
    expect($costsA)->toBe([300, 300])->and($costsB)->toBe([300, 300]);
});

it('K-10 never reports a missing cost as a complete food cost', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $cup = p4Product($c, 'Sauce cup', '0.200', ['stock_mode' => 'unit', 'cost_price' => '0.050']);
    $sandwich = p4Product($c, 'Bought sandwich', '1.500', ['stock_mode' => 'unit']); // no cost price
    lc2Component($sandwich->id, $cup->id, '1');
    $noCost = p3Ingredient($c, 'New spice', 'g', '0');
    $dish = p4Product($c, 'Spiced rice', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($dish, [[$noCost, '5']]);

    $rows = lc2Rows();
    expect([$rows['Bought sandwich']['status'], $rows['Bought sandwich']['cost_complete'], $rows['Bought sandwich']['cost_baisas']])->toBe(['incomplete', false, 50])
        ->and([$rows['Spiced rice']['status'], $rows['Spiced rice']['cost_baisas']])->toBe(['incomplete', 0])
        ->and($rows['Sauce cup']['status'])->toBe('ok');
    // Fix order 3 (K-14): they count as costed, and as missing a cost.
    expect($this->getJson('/api/dashboard/summary')->json('data.dishes_over_target'))->toMatchArray(['costed' => 3, 'incomplete' => 2]);
});

it('K-11 shows one cost in a Recipe & Cost row: cost, profit, margin and food cost agree', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $beef = p3Ingredient($c, 'Beef', 'g', '0.004000');
    $patty = p4Product($c, 'Patty', '0.800', ['stock_mode' => 'cooked']);
    lcRecipe($patty, [[$beef, '75']]); // 0.300 a piece
    $burger = p4Product($c, 'Burger', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($burger, [[$beef, '50']]); // 0.200
    lc2Component($burger->id, $patty->id, '1');

    $row = collect($this->getJson('/api/reports/recipe-cost?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()->json('data.rows'))->firstWhere('product_name', 'Burger');
    expect([$row['theoretical_cost'], $row['profit_per_unit'], $row['margin_pct'], $row['food_cost_pct'], $row['recipe_cost']])
        ->toEqual(['0.500', '0.500', 50.0, 50.0, '0.200']);
});

it('K-12 keeps a physical item\'s "may contain" when a save does not send it', function (): void {
    $ctx = makeMerchantActor();
    $item = p4Product($ctx['company'], 'Topping sachet', '0.100', ['is_internal' => true, 'internal_purpose' => 'general', 'stock_mode' => 'unit']);
    $url = '/api/products/'.$item->uuid.'/allergens';
    $this->putJson($url, ['contains' => ['milk'], 'may_contain' => ['peanuts']])->assertOk();

    // The Inventory modal saves the "contains" ticks only.
    $this->putJson($url, ['contains' => ['milk', 'soy']])->assertOk()
        ->assertJsonPath('data.own_contains', ['soy', 'milk'])
        ->assertJsonPath('data.own_may_contain', ['peanuts']);
    $listed = collect($this->getJson('/api/physical-items')->assertOk()->json('data'))->firstWhere('uuid', $item->uuid);
    expect([$listed['allergens'], $listed['may_contain']])->toBe([['soy', 'milk'], ['peanuts']]);
    // An explicit empty list still clears it.
    $this->putJson($url, ['contains' => ['milk'], 'may_contain' => []])->assertOk()->assertJsonPath('data.own_may_contain', []);
});

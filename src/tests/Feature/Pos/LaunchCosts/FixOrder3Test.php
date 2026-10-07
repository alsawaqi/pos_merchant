<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens, Part A — fix order 3 (LAUNCH-COSTS_A_FIX_ORDER_3.md).
 *
 *   K-13  a made-to-order dish built only from costed components is complete
 *   K-14  the dashboard card never reads "2 over target, 0 costed"
 *   K-15  the Recipe & Cost row shows the recipe part next to Actual and Change
 *   K-16  combos and meals cost their dearest REAL order type
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function lc3Component(int $productId, int $componentId, string $qty, int $mask = 15): void
{
    DB::table('pos_product_components')->insert(['product_id' => $productId, 'component_product_id' => $componentId, 'quantity' => $qty,
        'order_types' => $mask, 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, array<string, mixed>> */
function lc3Rows(): array
{
    return collect(test()->getJson('/api/food-costs')->assertOk()->json('data'))->keyBy('name')->all();
}

it('K-13 counts a made-to-order dish made only of costed components as complete', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $beef = p3Ingredient($c, 'Beef', 'g', '0.004000');
    $patty = p4Product($c, 'Patty', '0.800', ['stock_mode' => 'cooked']);
    lcRecipe($patty, [[$beef, '75']]); // 0.300
    $bun = p4Product($c, 'Bun', '0.200', ['stock_mode' => 'unit', 'cost_price' => '0.050']);
    $burger = p4Product($c, 'Assembled burger', '1.000', ['stock_mode' => 'ingredient']); // no recipe of its own
    lc3Component($burger->id, $patty->id, '1');
    lc3Component($burger->id, $bun->id, '1');

    $row = lc3Rows()['Assembled burger'];
    expect([$row['status'], $row['cost_complete'], $row['cost_baisas'], $row['food_cost_pct']])->toEqual(['ok', true, 350, 35.0]);
});

it('K-14 keeps the dashboard card consistent with incomplete dishes over target', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $sauce = p4Product($c, 'Sauce cup', '0.400', ['stock_mode' => 'unit', 'cost_price' => '0.300']); // 75% — over, ok
    foreach (['Bought wrap', 'Bought sandwich'] as $name) {
        $item = p4Product($c, $name, '0.500', ['stock_mode' => 'unit']); // no cost price
        lc3Component($item->id, $sauce->id, '1'); // 0.300 / 0.500 = 60% at least — over, incomplete
    }

    expect($this->getJson('/api/dashboard/summary')->assertOk()->json('data.dishes_over_target'))
        ->toEqual(['count' => 3, 'costed' => 3, 'incomplete' => 2, 'no_recipe' => 0, 'target_percent' => 30]);
});

it('K-15 shows the recipe part next to Actual and Change in the Recipe & Cost report', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $beef = p3Ingredient($c, 'Beef', 'g', '0.004000');
    $patty = p4Product($c, 'Patty', '0.800', ['stock_mode' => 'cooked']);
    lcRecipe($patty, [[$beef, '75']]);
    $burger = p4Product($c, 'Burger', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($burger, [[$beef, '50']]);
    lc3Component($burger->id, $patty->id, '1');
    p4PaidOrder($ctx['branch'], ['subtotal' => '2.000', 'grand_total' => '2.000'], [[$burger, '2', '1.000', '2.000', [
        'recipe_snapshot_json' => json_encode([['ingredient_id' => $beef->id, 'qty' => '50', 'unit' => 'g', 'unit_cost' => '0.004']]),
        'component_snapshot_json' => json_encode([]),
    ]]]);

    $row = collect($this->getJson('/api/reports/recipe-cost?date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()->json('data.rows'))->firstWhere('product_name', 'Burger');
    expect([$row['theoretical_cost'], $row['recipe_cost'], $row['actual_cost_per_unit'], $row['cost_change_per_unit'], $row['units_sold']])
        ->toBe(['0.500', '0.200', '0.200', '0.000', '2.000']);
});

it('K-16 costs a combo and a meal at their dearest real order type', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $beef = p3Ingredient($c, 'Beef', 'g', '0.004000');
    $plate = p3Ingredient($c, 'Plate garnish', 'piece', '0.200000');
    $potato = p3Ingredient($c, 'Potato', 'g', '0.001000');
    $box = p3Ingredient($c, 'Fries box', 'piece', '0.150000');
    $burgers = lcCategory($c, 'Burgers');
    $burger = p4Product($c, 'Burger', '2.000', ['stock_mode' => 'ingredient', 'category_id' => $burgers]);
    lcRecipe($burger, [[$beef, '75'], [$plate, '1', 1]]);   // 0.300, + 0.200 dine in
    $fries = p4Product($c, 'Fries', '1.000', ['stock_mode' => 'ingredient']);
    lcRecipe($fries, [[$potato, '100'], [$box, '1', 8]]);   // 0.100, + 0.150 delivery
    $combo = p4Product($c, 'Burger box', '3.000', ['product_type' => 'combo']);
    lcLine($c, ['combo_product_id' => $combo->id], ['kind' => 'fixed', 'product_id' => $burger->id, 'quantity' => 1]);
    lcLine($c, ['combo_product_id' => $combo->id], ['kind' => 'fixed', 'product_id' => $fries->id, 'quantity' => 1]);
    $meal = lcMeal($c, 'meal', '1.000', [$burgers]);
    lcLine($c, ['meal_id' => $meal], ['kind' => 'fixed', 'product_id' => $fries->id, 'quantity' => 1]);

    // dine in 0.500 + 0.100 = 0.600; delivery 0.300 + 0.250 = 0.550; quick / to go 0.400 → 0.600 (never 0.500 + 0.250).
    $rows = lc3Rows();
    expect([$rows['Burger box']['cost_baisas'], $rows['Burger meal']['cost_baisas']])->toBe([600, 600])
        ->and($rows['Burger box']['food_cost_pct'])->toEqual(20.0);
});

<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-5 — food cost completeness in the portal reports.
 *
 * Cost of goods in the Sales and Product-performance reports now includes
 * cooked products (the batch cost per piece pos_api stamps on the "produced"
 * movement; old sales fall back to cost price / today's recipe), packaging,
 * add-on option lines and add-ons that are products — all from the order
 * line's frozen copies. The Recipe & Cost report applies its date and branch
 * filters to what was sold, and its developer placeholder is gone.
 */

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function p3Produced(array $ctx, Product $product, ?Branch $branch, string $at, ?string $unitCost): void
{
    DB::table('pos_product_stock_movements')->insert([
        'company_id' => $ctx['company']->id,
        'product_id' => $product->id,
        'branch_id' => $branch?->id,
        'movement_type' => 'produced',
        'quantity' => '10.000',
        'unit_cost' => $unitCost,
        'reference_type' => 'pos_productions',
        'reference_id' => 1,
        'occurred_at' => $at,
        'created_at' => $at,
    ]);
}

function p3Sale(array $ctx, Branch $branch, string $at): Order
{
    return Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000', 'opened_at' => $at, 'closed_at' => $at,
    ]);
}

function p3Line(Order $order, ?Product $product, string $qty, array $attributes = []): OrderItem
{
    return OrderItem::factory()->for($order, 'order')->create($attributes + [
        'product_id' => $product?->id,
        'product_name_snapshot' => $product?->name ?? 'x',
        'qty' => $qty,
        'unit_price_snapshot' => '1.000',
        'line_total' => '1.000',
        'recipe_snapshot_json' => null,
    ]);
}

function p3Addon(OrderItem $item, array $columns): void
{
    OrderItemAddon::factory()->for($item, 'orderItem')->create(array_map(
        static fn ($v) => is_array($v) ? json_encode($v) : $v,
        $columns + ['ingredient_snapshot_json' => null],
    ));
}

/**
 * Burger (made-to-order) ×2: recipe 0.2 × 1.000, a cup (0.050), "no onion" removing 0.3 of the
 * same ingredient (clamps the recipe line to 0) and "extra patty" (+1 cooked patty);
 * a standalone cooked patty ×3; a coffee ×1 with a cake add-on (bought-in, 0.300, its own cup)
 * and a fries add-on (made-to-order recipe 2 × 0.050).
 */
function p3FoodCostFixture(array $ctx): array
{
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging', 'cost_price' => '0.050']);
    $patty = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Patty', 'stock_mode' => 'cooked', 'cost_price' => null]);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'unit', 'cost_price' => '0.300']);
    $burger = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Burger', 'stock_mode' => 'ingredient']);
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'stock_mode' => 'ingredient']);

    p3Produced($ctx, $patty, $ctx['branch'], '2026-06-10 08:00:00', '0.400000');
    p3Produced($ctx, $patty, $ctx['branch'], '2026-06-20 08:00:00', '0.500000');

    $order = p3Sale($ctx, $ctx['branch'], '2026-06-15 12:00:00');
    $burgerLine = p3Line($order, $burger, '2.000', [
        'recipe_snapshot_json' => [['ingredient_id' => 1, 'qty' => 0.2, 'unit' => 'kg', 'unit_cost' => 1.0]],
        'component_snapshot_json' => json_encode([['product_id' => $cup->id, 'qty' => 1]]),
    ]);
    p3Addon($burgerLine, ['consumption_snapshot_json' => [['type' => 'ingredient', 'ingredient_id' => 1, 'direction' => 'remove', 'qty' => 0.3, 'unit' => 'kg', 'unit_cost' => 1.0]]]);
    p3Addon($burgerLine, ['consumption_snapshot_json' => [['type' => 'product', 'product_id' => $patty->id, 'direction' => 'add', 'qty' => 1]]]);

    p3Line($order, $patty, '3.000', ['component_snapshot_json' => '[]']);

    $coffeeLine = p3Line($order, $coffee, '1.000', [
        'recipe_snapshot_json' => [['ingredient_id' => 2, 'qty' => 1, 'unit' => 'g', 'unit_cost' => 0.1]],
        'component_snapshot_json' => '[]',
    ]);
    p3Addon($coffeeLine, ['linked_product_id' => $cake->id, 'product_snapshot_json' => [
        'product_id' => $cake->id, 'stock_mode' => 'unit', 'recipe' => null, 'components' => [['product_id' => $cup->id, 'qty' => 1]],
    ]]);
    p3Addon($coffeeLine, ['product_snapshot_json' => [
        'product_id' => 999999, 'stock_mode' => 'ingredient', 'recipe' => [['ingredient_id' => 3, 'qty' => 2, 'unit' => 'g', 'unit_cost' => 0.05]], 'components' => null,
    ]]);

    return compact('cup', 'patty', 'cake', 'burger', 'coffee');
}

it('counts packaging, option lines, add-on products and cooked batches in the Sales report COGS', function (): void {
    $ctx = makeMerchantActor();
    p3FoodCostFixture($ctx);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    // Burger: (recipe 0.2 − 0.3 → 0) + cup 0.050 + patty batch 0.400 = 0.450 × 2 = 0.900
    // Patty:  batch finished at the branch before the sale 0.400 × 3       = 1.200
    // Coffee: recipe 0.100 + cake 0.300 + its cup 0.050 + fries 0.100     = 0.550
    expect($response->json('data.headline.cogs'))->toBe('2.650')
        ->and($response->json('data.headline.gross_profit'))->toBe('7.350');
});

it('attributes the complete line cost to each product in the Product-performance report', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3FoodCostFixture($ctx);

    $rows = collect($this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.top_by_revenue'))
        ->keyBy('product_id');

    expect($rows[$fx['burger']->id]['recipe_cost'])->toBe('0.900')
        ->and($rows[$fx['patty']->id]['recipe_cost'])->toBe('1.200')
        ->and($rows[$fx['coffee']->id]['recipe_cost'])->toBe('0.550');
});

it('costs a cooked piece from the latest batch at the sale\'s branch, else anywhere, else today\'s recipe', function (): void {
    $ctx = makeMerchantActor();
    $beef = p3Ingredient($ctx['company'], 'Beef', 'g', '0.003');
    $patty = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Patty', 'stock_mode' => 'cooked']);
    ProductRecipe::query()->create(['product_id' => $patty->id, 'ingredient_id' => $beef->id, 'quantity' => '100', 'unit_at_set' => 'g']);
    $north = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'North']);
    $south = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'South']);
    p3Produced($ctx, $patty, $north, '2026-06-10 08:00:00', '0.900000');
    p3Produced($ctx, $patty, $ctx['branch'], '2026-06-12 08:00:00', '0.500000');
    // A batch finished before Part B stamped costs carries none: ignored.
    p3Produced($ctx, $patty, $north, '2026-06-13 08:00:00', null);

    p3Line(p3Sale($ctx, $north, '2026-06-15 12:00:00'), $patty, '1.000');         // North's own batch: 0.900
    p3Line(p3Sale($ctx, $south, '2026-06-15 12:00:00'), $patty, '1.000');         // no South batch: latest anywhere 0.500
    p3Line(p3Sale($ctx, $ctx['branch'], '2026-06-05 12:00:00'), $patty, '1.000'); // before any batch: recipe 100 × 0.003 = 0.300

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.headline.cogs'))->toBe('1.700');
});

it('applies the Recipe & Cost report\'s date and branch filters to what was sold, and costs recipes through prep items', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]); // 0.0015 per ml
    $pizza = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Pizza', 'stock_mode' => 'ingredient', 'base_price' => '3.000']);
    ProductRecipe::query()->create(['product_id' => $pizza->id, 'ingredient_id' => $sauce->id, 'quantity' => '200', 'unit_at_set' => 'ml']);
    $other = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Other']);

    // Sold at 0.250 per pizza (the frozen raw lines), in June at the main branch only.
    $frozen = [['ingredient_id' => $tomato->id, 'qty' => 125, 'unit' => 'g', 'unit_cost' => 0.002]];
    p3Line(p3Sale($ctx, $ctx['branch'], '2026-06-10 12:00:00'), $pizza, '2.000', ['recipe_snapshot_json' => $frozen, 'line_total' => '6.000']);
    p3Line(p3Sale($ctx, $other, '2026-06-11 12:00:00'), $pizza, '5.000', ['recipe_snapshot_json' => $frozen, 'line_total' => '15.000']);
    p3Line(p3Sale($ctx, $ctx['branch'], '2026-05-10 12:00:00'), $pizza, '9.000', ['recipe_snapshot_json' => $frozen, 'line_total' => '27.000']);

    $june = $this->getJson("/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30&branch_ids[]={$ctx['branch']->id}")->assertOk()->json('data');
    $row = $june['rows'][0];

    // Today's recipe through the prep item: 200 ml × 0.0015 = 0.300.
    expect($row)->toMatchArray([
        'product_name' => 'Pizza',
        'theoretical_cost' => '0.300',
        'profit_per_unit' => '2.700',
        'units_sold' => '2.000',
        'revenue' => '6.000',
        'actual_cost_per_unit' => '0.250',
        'cost_change_per_unit' => '-0.050',
    ])->and($june)->not->toHaveKey('_phase');

    $all = $this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows.0');
    expect($all['units_sold'])->toBe('7.000');

    $none = $this->getJson('/api/reports/recipe-cost?date_from=2026-07-01&date_to=2026-07-31')->assertOk()->json('data.rows.0');
    expect($none['units_sold'])->toBe('0.000')
        ->and($none['actual_cost_per_unit'])->toBeNull()
        ->and($none['theoretical_cost'])->toBe('0.300');
});

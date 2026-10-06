<?php

declare(strict_types=1);

/**
 * LAUNCH packaging add-on, part B3 — reports (work order §2.7, §3 Part B3;
 * audit §2.5).
 *
 * A line's cost uses only the copied lines ticked for the type its stock was
 * taken for (pos_orders.stock_order_type, else the order type; car = to go)
 * — like the stock deduction; untagged copies count for every type, and a
 * legacy line costed from LIVE components is never filtered. The per-order
 * packaging (pos_orders.packaging_snapshot_json) is in the Sales report's
 * COGS once per order, and in no product's performance or recipe cost. The
 * Recipe & Cost report shows the recipe's cost per type once a line is
 * ticked, and compares the actual cost with the same mix of types.
 * Before (81a59b4): every copied line was costed; packaging was never counted.
 */

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function pkOrder(array $ctx, string $type, ?string $stamped, ?array $packaging = null, string $at = '2026-06-15 12:00:00'): Order
{
    return Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'order_type' => $type,
        'stock_order_type' => $stamped,
        'packaging_snapshot_json' => $packaging === null ? null : json_encode($packaging),
        'subtotal' => '2.000', 'grand_total' => '2.000', 'opened_at' => $at, 'closed_at' => $at,
    ]);
}

function pkLine(Order $order, Product $product, array $attributes = []): OrderItem
{
    // recipe_snapshot_json has an array cast; the component copy is raw text.
    if (is_array($attributes['component_snapshot_json'] ?? null)) {
        $attributes['component_snapshot_json'] = json_encode($attributes['component_snapshot_json']);
    }

    return OrderItem::factory()->for($order, 'order')->create(array_merge(
        [
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'qty' => '1.000',
            'unit_price_snapshot' => '1.000',
            'line_total' => '1.000',
            'recipe_snapshot_json' => null,
            'component_snapshot_json' => '[]',
        ],
        $attributes,
    ));
}

/** A latte copy: milk 0.200 for all types, sugar 0.010 for all, cup 0.050 and lid 0.020 for to go + delivery + quick. */
function pkLatteCopy(array $ctx): array
{
    $latte = pkProduct($ctx['company'], 'Latte');
    $cup = pkItem($ctx['company'], 'Cup', '0.050');
    $lid = pkItem($ctx['company'], 'Lid', '0.020');

    return [$latte, [
        'recipe_snapshot_json' => [
            ['ingredient_id' => 1, 'qty' => 200, 'unit' => 'ml', 'unit_cost' => 0.001],
            ['ingredient_id' => 2, 'qty' => 5, 'unit' => 'g', 'unit_cost' => 0.002],
        ],
        'component_snapshot_json' => [
            ['product_id' => $cup->id, 'qty' => 1, 'order_types' => 14],
            ['product_id' => $lid->id, 'qty' => 1, 'order_types' => 14],
        ],
    ]];
}

it('costs a latte sold dine in without the cup and lid, and to go with them', function (): void {
    $ctx = makeMerchantActor();
    [$latte, $copy] = pkLatteCopy($ctx);

    pkLine(pkOrder($ctx, 'dine_in', 'dine_in'), $latte, $copy);
    $sales = fn () => $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($sales()->json('data.headline.cogs'))->toBe('0.210');

    // To go: + cup 0.050 + lid 0.020. An order switched to To go before
    // payment was stamped to_go when its stock was taken.
    pkLine(pkOrder($ctx, 'to_go', 'to_go'), $latte, $copy);
    expect($sales()->json('data.headline.cogs'))->toBe('0.490');

    // No stamp: the order's own type decides (car = to go).
    pkLine(pkOrder($ctx, 'car', null), $latte, $copy);
    expect($sales()->json('data.headline.cogs'))->toBe('0.770');

    $rows = collect($this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.top_by_revenue'))->keyBy('product_id');
    expect($rows[$latte->id]['recipe_cost'])->toBe('0.770');
});

it('filters option stock lines and the legacy trio by the order type, and keeps a legacy live component unfiltered', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte');
    $mug = pkItem($ctx['company'], 'Mug sleeve', '0.030');
    $cupL = pkItem($ctx['company'], 'Cup 12oz', '0.060');
    $cupS = pkItem($ctx['company'], 'Cup 8oz', '0.050');

    // A legacy line (no component copy) costs the LIVE components, ticked
    // dine in only today, unfiltered: old code took every component.
    pkComponent($latte, $mug, '1', 1);
    pkLine(pkOrder($ctx, 'to_go', 'to_go'), $latte, ['component_snapshot_json' => null]);
    expect($this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.headline.cogs'))->toBe('0.030');

    // "Large" swaps cups only for to go: dine in costs nothing extra.
    foreach (['dine_in', 'to_go'] as $type) {
        $order = pkOrder($ctx, $type, $type, null, '2026-07-15 12:00:00');
        $line = pkLine($order, $latte, ['component_snapshot_json' => [['product_id' => $cupS->id, 'qty' => 1, 'order_types' => 4]]]);
        OrderItemAddon::factory()->for($line, 'orderItem')->create([
            'ingredient_snapshot_json' => ['ingredient_id' => 9, 'qty' => 10, 'unit' => 'g', 'unit_cost' => 0.003, 'order_types' => 1],
            'consumption_snapshot_json' => null,
        ]);
        OrderItemAddon::factory()->for($line, 'orderItem')->create([
            'ingredient_snapshot_json' => null,
            'consumption_snapshot_json' => json_encode([
                ['type' => 'product', 'product_id' => $cupL->id, 'direction' => 'add', 'qty' => 1, 'order_types' => 4],
                ['type' => 'product', 'product_id' => $cupS->id, 'direction' => 'remove', 'qty' => 1, 'order_types' => 4],
            ]),
        ]);
        $cogs = $this->getJson('/api/reports/sales?date_from=2026-07-01&date_to=2026-07-31')->assertOk()->json('data.headline.cogs');
        // dine in: the legacy trio (dine in only) 10 × 0.003 = 0.030, no cups;
        // + to go: cup 8oz 1 − 1 = 0, cup 12oz 0.060, no trio → 0.030 + 0.060.
        expect($cogs)->toBe($type === 'dine_in' ? '0.030' : '0.090');
    }
});

it('counts the per-order packaging once in the Sales COGS, and never in product performance', function (): void {
    $ctx = makeMerchantActor();
    [$latte, $copy] = pkLatteCopy($ctx);
    $bag = pkItem($ctx['company'], 'Delivery bag', '0.040');

    $order = pkOrder($ctx, 'delivery', 'delivery', ['order_type' => 'delivery', 'lines' => [
        ['type' => 'ingredient', 'ingredient_id' => 5, 'qty' => 10, 'unit' => 'g', 'unit_cost' => 0.001],
        ['type' => 'product', 'product_id' => $bag->id, 'qty' => 1],
    ]]);
    for ($i = 0; $i < 5; $i++) {
        pkLine($order, $latte, $copy);
    }

    $headline = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.headline');
    // 5 × 0.280 (to go set: delivery is ticked too) + packaging 0.010 + 0.040 once.
    expect($headline['cogs'])->toBe('1.450')
        ->and($headline['cogs_packaging'])->toBe('0.050');

    $rows = collect($this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.top_by_revenue'))->keyBy('product_id');
    expect($rows[$latte->id]['recipe_cost'])->toBe('1.400');
});

it('shows the recipe cost per order type and compares the actual cost with the same mix of types', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte', 'ingredient', ['base_price' => '2.000']);
    $milk = pkIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $napkin = pkIngredient($ctx['company'], 'Napkin', 'piece', '0.010');
    pkRecipeLine($latte, $milk, '200', null, 0);
    pkRecipeLine($latte, $napkin, '1', 1, 1);
    pkRecipeLine($latte, $napkin, '3', 12, 2);

    $copy = ['recipe_snapshot_json' => [
        ['ingredient_id' => $milk->id, 'qty' => 200, 'unit' => 'ml', 'unit_cost' => 0.001],
        ['ingredient_id' => $napkin->id, 'qty' => 1, 'unit' => 'piece', 'unit_cost' => 0.01, 'order_types' => 1],
        ['ingredient_id' => $napkin->id, 'qty' => 3, 'unit' => 'piece', 'unit_cost' => 0.01, 'order_types' => 12],
    ]];
    pkLine(pkOrder($ctx, 'dine_in', 'dine_in'), $latte, $copy);
    pkLine(pkOrder($ctx, 'to_go', 'to_go'), $latte, $copy);

    $row = $this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows.0');
    expect($row['theoretical_by_type'])->toBe(['dine_in' => '0.210', 'quick' => '0.200', 'to_go' => '0.230', 'delivery' => '0.230'])
        ->and($row['theoretical_cost'])->toBe('0.230')
        ->and($row['actual_cost_per_unit'])->toBe('0.220')
        ->and($row['cost_change_per_unit'])->toBe('0.000');

    // An untagged recipe has no per-type cost.
    $tea = pkProduct($ctx['company'], 'Tea');
    pkRecipeLine($tea, $milk, '50');
    $rows = collect($this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows'))->keyBy('product_id');
    expect($rows[$tea->id]['theoretical_by_type'])->toBeNull();
});

<?php

declare(strict_types=1);

/**
 * Phase 7b-3 — Product Performance Report coverage (blueprint
 * §5.11.2).
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ranks products by revenue (top_by_revenue) + by quantity (top_by_qty)', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'base_price' => '2.500']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'base_price' => '5.000']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    // 10 lattes: revenue 25.000, qty 10
    for ($i = 0; $i < 10; $i++) {
        OrderItem::factory()->for($order, 'order')->for($latte, 'product')->create([
            'product_name_snapshot' => 'Latte',
            'qty' => '1.000',
            'unit_price_snapshot' => '2.500',
            'line_total' => '2.500',
        ]);
    }
    // 3 cakes: revenue 15.000, qty 3
    for ($i = 0; $i < 3; $i++) {
        OrderItem::factory()->for($order, 'order')->for($cake, 'product')->create([
            'product_name_snapshot' => 'Cake',
            'qty' => '1.000',
            'unit_price_snapshot' => '5.000',
            'line_total' => '5.000',
        ]);
    }

    $response = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byRevenue = $response->json('data.top_by_revenue');
    expect($byRevenue[0]['product_name'])->toBe('Latte'); // 25 > 15
    expect($byRevenue[0]['revenue'])->toBe('25.000');
    expect($byRevenue[1]['product_name'])->toBe('Cake');

    $byQty = $response->json('data.top_by_qty');
    expect($byQty[0]['product_name'])->toBe('Latte'); // 10 > 3
    expect((float) $byQty[0]['qty_sold'])->toBe(10.0);
});

it('lists slow movers below the threshold (default 3)', function (): void {
    $ctx = makeMerchantActor();
    $slow = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Slow Item']);
    $hot = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Item']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    // 1 slow item (< 3)
    OrderItem::factory()->for($order, 'order')->for($slow, 'product')->create([
        'product_name_snapshot' => 'Slow Item',
        'qty' => '1.000', 'line_total' => '5.000',
    ]);
    // 5 hot items (>= 3)
    for ($i = 0; $i < 5; $i++) {
        OrderItem::factory()->for($order, 'order')->for($hot, 'product')->create([
            'product_name_snapshot' => 'Hot Item',
            'qty' => '1.000', 'line_total' => '5.000',
        ]);
    }

    $response = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $slowMovers = $response->json('data.slow_movers');
    expect($slowMovers)->toHaveCount(1);
    expect($slowMovers[0]['product_name'])->toBe('Slow Item');
});

it('returns empty arrays when no orders fall in the window', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.top_by_revenue'))->toBe([]);
    expect($response->json('data.top_by_qty'))->toBe([]);
    expect($response->json('data.slow_movers'))->toBe([]);
    expect($response->json('data.top_addons'))->toBe([]);
});

it('does not leak product data from another company', function (): void {
    $ctx = makeMerchantActor();
    $mine = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Mine']);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    OrderItem::factory()->for($order, 'order')->for($mine, 'product')->create([
        'product_name_snapshot' => 'Mine',
        'line_total' => '5.000',
    ]);

    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    $foreign = Product::factory()->for($other, 'company')->create();
    $foreignOrder = Order::factory()->for($other, 'company')->for($otherBranch, 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    OrderItem::factory()->for($foreignOrder, 'order')->for($foreign, 'product')->create([
        'product_name_snapshot' => 'Foreign',
        'line_total' => '999.000',
    ]);

    $response = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.top_by_revenue'))->toHaveCount(1);
    expect($response->json('data.top_by_revenue.0.product_name'))->toBe('Mine');
});

it('computes per-product recipe_cost, profit and margin from snapshots', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'base_price' => '2.500']);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    // 4 lattes @2.500 → revenue 10.000; recipe per unit 0.25 × 0.400 = 0.100 → cost 0.400.
    for ($i = 0; $i < 4; $i++) {
        OrderItem::factory()->for($order, 'order')->for($latte, 'product')->create([
            'product_name_snapshot' => 'Latte', 'qty' => '1.000', 'unit_price_snapshot' => '2.500', 'line_total' => '2.500',
            'recipe_snapshot_json' => [['ingredient_id' => 1, 'qty' => 0.25, 'unit' => 'l', 'unit_cost' => 0.400]],
        ]);
    }

    $response = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $row = collect($response->json('data.top_by_revenue'))->firstWhere('product_name', 'Latte');
    expect($row['revenue'])->toBe('10.000');
    expect($row['recipe_cost'])->toBe('0.400'); // 4 × 0.100
    expect($row['profit'])->toBe('9.600');
    expect($row['margin_pct'])->toEqual(96.0); // (9.6 / 10) × 100
});

<?php

declare(strict_types=1);

/**
 * Customer 360 analytics + order history endpoints (v2 #8).
 *
 *   GET /api/customers/{uuid}/analytics — lifetime rollups, favorite item,
 *                                          12-month spend trend (paid only)
 *   GET /api/customers/{uuid}/orders    — paginated order history (all
 *                                          statuses), paid-total banner
 *
 * reports.view gated, tenant-scoped (cross-tenant uuid = 404).
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Seed two paid orders (May + June) + one void, with line items, for $customer. */
function seedCustomerOrders(array $ctx, Customer $customer): void
{
    // Shared products so re-orders of "Latte" merge by product_id (as in prod).
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake']);
    $juice = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Juice']);

    $may = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $customer->id, 'grand_total' => '30.000', 'opened_at' => '2026-05-20 10:00:00',
    ]);
    OrderItem::factory()->for($may, 'order')->for($latte, 'product')->create(['product_name_snapshot' => 'Latte', 'qty' => '2.000', 'unit_price_snapshot' => '5.000', 'line_total' => '10.000']);
    OrderItem::factory()->for($may, 'order')->for($cake, 'product')->create(['product_name_snapshot' => 'Cake', 'qty' => '1.000', 'unit_price_snapshot' => '20.000', 'line_total' => '20.000']);

    $june = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $customer->id, 'grand_total' => '20.000', 'opened_at' => '2026-06-12 10:00:00',
    ]);
    OrderItem::factory()->for($june, 'order')->for($latte, 'product')->create(['product_name_snapshot' => 'Latte', 'qty' => '3.000', 'unit_price_snapshot' => '5.000', 'line_total' => '15.000']);
    OrderItem::factory()->for($june, 'order')->for($juice, 'product')->create(['product_name_snapshot' => 'Juice', 'qty' => '1.000', 'unit_price_snapshot' => '5.000', 'line_total' => '5.000']);

    // A void order — counts in the order LIST but never in rollups/favorite/trend.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'customer_id' => $customer->id, 'status' => 'void', 'grand_total' => '99.000', 'opened_at' => '2026-06-13 10:00:00',
    ]);
}

it('returns lifetime rollups + favorite item (paid only)', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    seedCustomerOrders($ctx, $alice);

    $data = $this->getJson("/api/customers/{$alice->uuid}/analytics")->assertOk()->json('data');

    expect($data['rollups']['order_count'])->toBe(2);          // void excluded
    expect($data['rollups']['total_spend'])->toBe('50.000');   // 30 + 20
    expect($data['rollups']['avg_ticket'])->toBe('25.000');
    expect($data['rollups']['first_order_at'])->toContain('2026-05-20');
    expect($data['rollups']['last_order_at'])->toContain('2026-06-12');

    // Latte: 2 + 3 = 5 qty (beats Cake 1, Juice 1); revenue 10 + 15 = 25.
    expect($data['favorite_item']['product_name'])->toBe('Latte');
    expect($data['favorite_item']['total_qty'])->toBe('5.000');
    expect($data['favorite_item']['total_revenue'])->toBe('25.000');
});

it('builds a 12-month spend trend ending this month', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    seedCustomerOrders($ctx, $alice);

    $trend = $this->getJson("/api/customers/{$alice->uuid}/analytics")->assertOk()->json('data.spend_trend');

    expect($trend)->toHaveCount(12);
    expect($trend[11]['month'])->toBe('2026-06');
    expect($trend[11]['gross'])->toBe('20.000');
    expect($trend[11]['count'])->toBe(1);
    expect($trend[10]['month'])->toBe('2026-05');
    expect($trend[10]['gross'])->toBe('30.000');
    expect($trend[0]['month'])->toBe('2025-07');
    expect($trend[0]['gross'])->toBe('0.000');
});

it('returns favorite_item null + zero rollups for a customer with no orders', function (): void {
    $ctx = makeMerchantActor();
    $bob = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Bob']);

    $data = $this->getJson("/api/customers/{$bob->uuid}/analytics")->assertOk()->json('data');

    expect($data['rollups']['order_count'])->toBe(0);
    expect($data['rollups']['total_spend'])->toBe('0.000');
    expect($data['favorite_item'])->toBeNull();
});

it('lists the customer order history newest-first with a paid total', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    seedCustomerOrders($ctx, $alice);

    $data = $this->getJson("/api/customers/{$alice->uuid}/orders")->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(3);                 // 2 paid + 1 void
    expect($data['totals']['count'])->toBe(3);
    expect($data['totals']['paid_total'])->toBe('50.000'); // void excluded from revenue
    // Newest first: the 2026-06-13 void row leads.
    expect($data['rows'][0]['status'])->toBe('void');
    expect($data['rows'][0]['opened_at'])->toContain('2026-06-13');
});

it('does not leak another tenant customer (404)', function (): void {
    makeMerchantActor();
    $foreign = Customer::factory()->create(['name' => 'Foreign']); // different company

    $this->getJson("/api/customers/{$foreign->uuid}/analytics")->assertNotFound();
    $this->getJson("/api/customers/{$foreign->uuid}/orders")->assertNotFound();
});

it('is gated under reports.view', function (): void {
    $ctx = makeMerchantActor();
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson("/api/customers/{$alice->uuid}/analytics")->assertForbidden();
    $this->getJson("/api/customers/{$alice->uuid}/orders")->assertForbidden();
});

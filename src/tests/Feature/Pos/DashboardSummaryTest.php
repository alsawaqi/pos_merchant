<?php

declare(strict_types=1);

/**
 * Phase 7b-7 — Dashboard summary endpoint coverage.
 *
 * Covers:
 *   - Permission gate (reports.view required, blocked otherwise)
 *   - Today / yesterday / MTD sales totals derived from
 *     paid orders only
 *   - Top product today derived from order_items snapshot
 *   - Low-stock count from pos_branch_stock vs ingredient min
 *   - Recent audit events surfaced (limit 5)
 *   - Tenant isolation
 */

use App\Enums\MerchantRole;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('is gated under reports.view', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/dashboard/summary')->assertForbidden();
});

it('returns zeros when no data exists', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/dashboard/summary')->assertOk();

    expect($response->json('data.today.gross'))->toBe('0.000');
    expect($response->json('data.today.order_count'))->toBe(0);
    expect($response->json('data.yesterday.gross'))->toBe('0.000');
    expect($response->json('data.mtd.gross'))->toBe('0.000');
    expect($response->json('data.top_product_today'))->toBeNull();
    expect($response->json('data.low_stock_count'))->toBe(0);
    expect($response->json('data.recent_audit_events'))->toBe([]);

    // v2 graph payloads: empty top-N, zero-filled 14-day trend.
    expect($response->json('data.top_products'))->toBe([]);
    expect($response->json('data.top_branches'))->toBe([]);
    expect($response->json('data.top_customers'))->toBe([]);
    expect($response->json('data.top_staff'))->toBe([]);
    expect($response->json('data.top_ingredients'))->toBe([]);
    $trend = $response->json('data.sales_trend');
    expect($trend)->toHaveCount(14);
    expect($trend[13]['gross'])->toBe('0.000');
    expect($trend[13]['count'])->toBe(0);
});

it('builds a 14-day zero-filled sales trend ending today', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '42.000',
        'opened_at' => Carbon::now()->setTime(9, 0),
    ]);
    // An order well outside the 14-day window must not appear.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '99.000',
        'opened_at' => Carbon::now()->subDays(40),
    ]);

    $trend = $this->getJson('/api/dashboard/summary')->assertOk()->json('data.sales_trend');

    expect($trend)->toHaveCount(14);
    // Last bucket is today (2026-06-15) and carries the 42.000 order.
    expect($trend[13]['date'])->toBe('2026-06-15');
    expect($trend[13]['gross'])->toBe('42.000');
    expect($trend[13]['count'])->toBe(1);
    // First bucket is 13 days earlier and is empty.
    expect($trend[0]['date'])->toBe('2026-06-02');
    expect($trend[0]['gross'])->toBe('0.000');
});

it('ranks MTD top products, branches, customers and staff', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    $bob = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Bob']);
    $sam = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Sam']);
    $kim = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Kim']);

    // Alice + Sam: a 80.000 order. Bob + Kim: a 30.000 order.
    $big = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '80.000', 'opened_at' => Carbon::now()->setTime(10, 0),
        'customer_id' => $alice->id, 'staff_id' => $sam->id,
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '30.000', 'opened_at' => Carbon::now()->setTime(11, 0),
        'customer_id' => $bob->id, 'staff_id' => $kim->id,
    ]);

    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);
    OrderItem::factory()->for($big, 'order')->for($latte, 'product')->create([
        'product_name_snapshot' => 'Latte', 'qty' => '4.000', 'unit_price_snapshot' => '5.000',
    ]);

    $data = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');

    expect($data['top_products'][0]['product_name'])->toBe('Latte');
    expect($data['top_products'][0]['revenue'])->toBe('20.000');
    expect($data['top_branches'][0]['gross'])->toBe('110.000');
    expect($data['top_customers'][0]['customer_name'])->toBe('Alice');
    expect($data['top_customers'][0]['total_spend'])->toBe('80.000');
    expect($data['top_staff'][0]['staff_name'])->toBe('Sam');
    expect($data['top_staff'][0]['revenue'])->toBe('80.000');
});

it('ranks MTD top consumed ingredients from negative movements', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk', 'unit' => 'l']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Beans', 'unit' => 'kg']);

    // Milk consumed 7 (5 + 2), Beans consumed 3. Positive restocks ignored.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->adjustment('-5.000')->create(['occurred_at' => Carbon::now()]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->adjustment('-2.000')->create(['occurred_at' => Carbon::now()]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->adjustment('-3.000')->create(['occurred_at' => Carbon::now()]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->create(['quantity' => '50.000', 'occurred_at' => Carbon::now()]);

    $top = $this->getJson('/api/dashboard/summary')->assertOk()->json('data.top_ingredients');

    expect($top[0]['ingredient_name'])->toBe('Milk');
    expect($top[0]['consumed'])->toBe('7.000');
    expect($top[0]['unit'])->toBe('l');
    expect($top[1]['ingredient_name'])->toBe('Beans');
    expect($top[1]['consumed'])->toBe('3.000');
});

it('aggregates today + yesterday + MTD from paid orders', function (): void {
    // Freeze to mid-month: on the 1st of a month startOfMonth() IS today,
    // so the "earlier in the month" order below would fall into the today
    // bucket and break today.gross == 20.000. Mid-month keeps the three
    // buckets (today / yesterday / earlier-MTD) genuinely distinct.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    $today = Carbon::now()->setTime(12, 0);
    $yesterday = $today->copy()->subDay();
    $earlierInMonth = $today->copy()->startOfMonth()->setTime(10, 0);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '20.000',
        'opened_at' => $today,
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '30.000',
        'opened_at' => $yesterday,
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '15.000',
        'opened_at' => $earlierInMonth,
    ]);

    $response = $this->getJson('/api/dashboard/summary')->assertOk();

    expect($response->json('data.today.gross'))->toBe('20.000');
    expect($response->json('data.today.order_count'))->toBe(1);
    expect($response->json('data.yesterday.gross'))->toBe('30.000');
    expect($response->json('data.yesterday.order_count'))->toBe(1);
    // MTD includes today + earlier-in-month (yesterday IS within
    // the current month too unless today is the 1st).
    expect((float) $response->json('data.mtd.gross'))->toBeGreaterThanOrEqual(35.0);
});

it('picks the top product by revenue today', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);
    $tea = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Tea']);

    $today = Carbon::now()->setTime(12, 0);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => $today,
    ]);

    OrderItem::factory()->for($order, 'order')->for($latte, 'product')->create([
        'product_name_snapshot' => 'Latte',
        'qty' => '5.000',
        'unit_price_snapshot' => '2.000',
    ]);
    OrderItem::factory()->for($order, 'order')->for($tea, 'product')->create([
        'product_name_snapshot' => 'Tea',
        'qty' => '2.000',
        'unit_price_snapshot' => '1.500',
    ]);

    $response = $this->getJson('/api/dashboard/summary')->assertOk();

    // Latte: 5 * 2.000 = 10.000; Tea: 2 * 1.500 = 3.000. Latte wins.
    expect($response->json('data.top_product_today.product_name'))->toBe('Latte');
    expect($response->json('data.top_product_today.revenue'))->toBe('10.000');
});

it('counts ingredients below their min_stock_threshold', function (): void {
    $ctx = makeMerchantActor();
    $low = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Milk', 'min_stock_threshold' => '5.000']);
    $ok = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Beans', 'min_stock_threshold' => '2.000']);
    $noThreshold = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Sugar', 'min_stock_threshold' => null]);

    BranchStock::factory()->for($ctx['branch'], 'branch')->for($low, 'ingredient')->create(['quantity' => '3.000']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($ok, 'ingredient')->create(['quantity' => '10.000']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($noThreshold, 'ingredient')->create(['quantity' => '0.000']);

    $response = $this->getJson('/api/dashboard/summary')->assertOk();
    expect($response->json('data.low_stock_count'))->toBe(1);
});

it('returns the latest 5 audit events newest first', function (): void {
    $ctx = makeMerchantActor();

    for ($i = 0; $i < 7; $i++) {
        DB::table('pos_audit_logs')->insert([
            'company_id' => $ctx['company']->id,
            'actor_user_id' => $ctx['user']->id,
            'event' => "evt.{$i}",
            'created_at' => Carbon::now()->subMinutes($i),
        ]);
    }

    $response = $this->getJson('/api/dashboard/summary')->assertOk();

    $events = $response->json('data.recent_audit_events');
    expect($events)->toHaveCount(5);
    // Newest first → evt.0 (now) comes first.
    expect($events[0]['event'])->toBe('evt.0');
    expect($events[4]['event'])->toBe('evt.4');
});

it('does not leak other tenants data', function (): void {
    $ctx = makeMerchantActor();

    $foreign = Company::factory()->create();
    $foreignBranch = \App\Models\Branch::factory()->for($foreign, 'company')->create();

    Order::factory()->for($foreign, 'company')->for($foreignBranch, 'branch')->paid()->create([
        'grand_total' => '999.000',
        'opened_at' => Carbon::now(),
    ]);
    DB::table('pos_audit_logs')->insert([
        'company_id' => $foreign->id,
        'event' => 'foreign.secret',
        'created_at' => Carbon::now(),
    ]);

    $response = $this->getJson('/api/dashboard/summary')->assertOk();

    expect($response->json('data.today.gross'))->toBe('0.000');
    expect($response->json('data.recent_audit_events'))->toBe([]);
});

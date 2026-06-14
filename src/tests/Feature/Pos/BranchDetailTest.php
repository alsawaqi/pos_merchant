<?php

declare(strict_types=1);

/**
 * Branch detail endpoints (v2 #11).
 *
 *   GET /api/pos/branches/{uuid}/products  — per-branch catalog + unit stock (catalogue.view)
 *   GET /api/pos/branches/{uuid}/staff     — staff assigned to the branch (pos_staff.view)
 *   GET /api/pos/branches/{uuid}/activity  — sales snapshot + recent orders/shifts/movements (reports.view)
 *
 * All tenant-scoped (cross-tenant uuid = 404).
 */

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('lists products carried at the branch with per-branch availability + unit stock', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'base_price' => '2.500', 'stock_mode' => 'unit']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'base_price' => '5.000', 'stock_mode' => 'untracked']);
    // A product NOT carried at this branch (no pivot row) — excluded.
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Juice']);

    BranchProduct::create(['branch_id' => $ctx['branch']->id, 'product_id' => $latte->id, 'is_available' => true, 'stock_qty' => '12.000']);
    BranchProduct::create(['branch_id' => $ctx['branch']->id, 'product_id' => $cake->id, 'is_available' => false, 'stock_qty' => null]);

    $data = $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/products")->assertOk()->json('data');

    expect($data)->toHaveCount(2);
    $byName = collect($data)->keyBy('name');
    expect($byName['Latte']['is_available'])->toBeTrue();
    expect($byName['Latte']['stock_qty'])->toBe('12.000');
    expect($byName['Latte']['stock_mode'])->toBe('unit');
    expect($byName['Cake']['is_available'])->toBeFalse();
    expect($byName['Cake']['stock_qty'])->toBeNull();
});

it('lists staff assigned to the branch only', function (): void {
    $ctx = makeMerchantActor();
    $otherBranch = Branch::factory()->for($ctx['company'], 'company')->create();

    PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Sam']);
    PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Kim']);
    PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $otherBranch->id, 'name' => 'Otto']);

    $rows = $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/staff")->assertOk()->json('data');

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('name')->sort()->values()->all())->toBe(['Kim', 'Sam']);
});

it('returns the branch activity feed + sales snapshot', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Sam']);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);

    // Paid order today.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '20.000', 'opened_at' => Carbon::now()->setTime(10, 0), 'staff_id' => $staff->id, 'customer_id' => $customer->id,
    ]);
    // Paid order earlier in the month (counts MTD, not today).
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '30.000', 'opened_at' => Carbon::parse('2026-06-03 10:00:00'),
    ]);

    Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['staff_id' => $staff->id]);

    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($ingredient, 'ingredient')->create(['occurred_at' => Carbon::now()]);

    $data = $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/activity")->assertOk()->json('data');

    expect($data['sales']['today']['gross'])->toBe('20.000');
    expect($data['sales']['today']['count'])->toBe(1);
    expect($data['sales']['mtd']['gross'])->toBe('50.000'); // 20 + 30
    expect($data['recent_orders'])->toHaveCount(2);
    expect($data['recent_orders'][0]['staff_name'])->toBe('Sam');     // newest first
    expect($data['recent_orders'][0]['customer_name'])->toBe('Alice');
    // Money/qty in the activity arrays keep exact 3-decimal strings (decimal:3 cast).
    expect($data['recent_orders'][0]['grand_total'])->toBe('20.000');
    expect($data['recent_shifts'])->toHaveCount(1);
    expect($data['recent_shifts'][0]['staff_name'])->toBe('Sam');
    expect($data['recent_movements'])->toHaveCount(1);
    expect($data['recent_movements'][0]['ingredient_name'])->toBe('Milk');
    expect($data['recent_movements'][0]['quantity'])->toBe('5.000'); // StockMovement factory default

    // Sales-by-hour heatmap matrix (trailing 30 days). Both orders fall in
    // the window: 2026-06-15 is Monday (weekday 1) @10:00, 2026-06-03 is
    // Wednesday (weekday 3) @10:00.
    expect($data['hour_weekday']['window_days'])->toBe(30);
    $cells = collect($data['hour_weekday']['cells']);
    $mon = $cells->first(fn ($c) => $c['weekday'] === 1 && $c['hour'] === 10);
    $wed = $cells->first(fn ($c) => $c['weekday'] === 3 && $c['hour'] === 10);
    expect($mon['gross'])->toBe('20.000');
    expect($wed['gross'])->toBe('30.000');
    expect($cells)->toHaveCount(2);
});

it('returns branch analytics: top products, staff activity, and a sales trend', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Sam']);
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '20.000', 'opened_at' => Carbon::now()->setTime(10, 0), 'staff_id' => $staff->id,
    ]);
    OrderItem::factory()->for($order, 'order')->for($latte, 'product')->create([
        'product_name_snapshot' => 'Latte', 'qty' => '3.000', 'unit_price_snapshot' => '5.000', 'line_total' => '15.000',
    ]);

    $data = $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/activity")->assertOk()->json('data');

    // Top product by quantity sold; revenue is the net line_total (reconciles
    // with the Product Performance report, which also sums line_total).
    expect($data['top_products'])->toHaveCount(1);
    expect($data['top_products'][0]['product_name'])->toBe('Latte');
    expect($data['top_products'][0]['qty_sold'])->toBe('3.000');
    expect($data['top_products'][0]['revenue'])->toBe('15.000');

    // Staff activity: Sam, 1 paid order, 20 gross.
    expect($data['staff_activity'])->toHaveCount(1);
    expect($data['staff_activity'][0]['staff_name'])->toBe('Sam');
    expect($data['staff_activity'][0]['orders_paid'])->toBe(1);
    expect($data['staff_activity'][0]['revenue'])->toBe('20.000');

    // Sales trend: a zero-filled 30-day series; today (last point) carries 20.
    expect($data['window_days'])->toBe(30);
    expect($data['sales_trend'])->toHaveCount(30);
    $trend = $data['sales_trend'];
    $last = end($trend);
    expect($last['date'])->toBe('2026-06-15');
    expect($last['gross'])->toBe('20.000');
});

it('returns branch kitchen-production analytics', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $chef = PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Chef Sami']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);

    // seedProduction() is the shared helper from ProductionsControllerTest.
    seedProduction([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'product_id' => $cake->id,
        'started_by_staff_id' => $chef->id,
        'quantity' => '12.000',
        'status' => 'finished',
        'started_at' => Carbon::now()->setTime(9, 0),
        'finished_at' => Carbon::now()->setTime(9, 30),
        'duration_seconds' => 1800,
    ]);

    $data = $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/activity")->assertOk()->json('data');

    $kp = $data['kitchen_production'];
    expect($kp['totals']['batches'])->toBe(1);
    expect($kp['totals']['pieces'])->toBe('12.000');
    expect($kp['totals']['finished'])->toBe(1);
    expect($kp['totals']['avg_duration_seconds'])->toBe(1800);

    expect($kp['by_product'])->toHaveCount(1);
    expect($kp['by_product'][0]['product_name'])->toBe('Cake');
    expect($kp['by_product'][0]['pieces'])->toBe('12.000');

    // Zero-filled 30-day daily pieces trend; today (last point) carries 12.
    expect($kp['by_day'])->toHaveCount(30);
    $byDay = $kp['by_day'];
    $last = end($byDay);
    expect($last['date'])->toBe('2026-06-15');
    expect($last['pieces'])->toBe('12.000');
    expect($last['batches'])->toBe(1);

    expect(collect($kp['status_mix'])->firstWhere('status', 'finished')['count'])->toBe(1);
    expect($kp['timeline'])->toHaveCount(1);
    expect($kp['timeline'][0]['product_name'])->toBe('Cake');
    expect($kp['timeline'][0]['staff_name'])->toBe('Chef Sami');
});

it('does not leak another tenant branch (404 on every section)', function (): void {
    makeMerchantActor();
    $foreign = Branch::factory()->create(); // different company

    $this->getJson("/api/pos/branches/{$foreign->uuid}/products")->assertNotFound();
    $this->getJson("/api/pos/branches/{$foreign->uuid}/staff")->assertNotFound();
    $this->getJson("/api/pos/branches/{$foreign->uuid}/activity")->assertNotFound();
});

it('gates each section behind its own permission', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/products")->assertForbidden();
    $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/staff")->assertForbidden();
    $this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/activity")->assertForbidden();
});

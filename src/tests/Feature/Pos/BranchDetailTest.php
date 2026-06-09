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
    expect($data['recent_shifts'])->toHaveCount(1);
    expect($data['recent_shifts'][0]['staff_name'])->toBe('Sam');
    expect($data['recent_movements'])->toHaveCount(1);
    expect($data['recent_movements'][0]['ingredient_name'])->toBe('Milk');
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

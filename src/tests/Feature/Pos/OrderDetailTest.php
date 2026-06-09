<?php

declare(strict_types=1);

/**
 * Merchant single-order DETAIL endpoint (v2 #2 keystone).
 *
 * GET /api/orders/{uuid} — full order with items+addons, per-line +
 * order-level discounts (#4), payments, and loyalty points moved.
 * reports.view gated, tenant-scoped (unknown/cross-tenant = 404).
 */

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Payment;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedDetailedOrder(array $ctx): Order
{
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Sam']);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice', 'phone' => '+96890000001']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'staff_id' => $staff->id,
        'customer_id' => $customer->id,
        'plate_number' => 'A12345',
        'subtotal' => '10.000',
        'discount_total' => '2.000',
        'tax_total' => '0.000',
        'grand_total' => '8.000',
        'opened_at' => '2026-06-15 10:00:00',
        'note' => 'Extra hot',
    ]);

    $latte = OrderItem::factory()->for($order, 'order')->create([
        'product_name_snapshot' => 'Latte', 'qty' => '2.000',
        'unit_price_snapshot' => '3.000', 'line_discount' => '1.000', 'line_total' => '5.000',
    ]);
    OrderItem::factory()->for($order, 'order')->create([
        'product_name_snapshot' => 'Cake', 'qty' => '1.000',
        'unit_price_snapshot' => '5.000', 'line_discount' => '0.000', 'line_total' => '5.000',
    ]);
    OrderItemAddon::factory()->create([
        'order_item_id' => $latte->id, 'add_on_name_snapshot' => 'Oat milk', 'price_delta_snapshot' => '0.500',
    ]);

    DB::table('pos_order_discounts')->insert([
        [
            'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id,
            'order_item_id' => null, 'discount_id' => null, 'name_snapshot' => 'Happy Hour',
            'amount_type_snapshot' => 'percent', 'amount' => '1.000', 'applied_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id,
            'order_item_id' => $latte->id, 'discount_id' => null, 'name_snapshot' => 'Latte promo',
            'amount_type_snapshot' => 'fixed', 'amount' => '1.000', 'applied_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    Payment::factory()->create([
        'order_id' => $order->id, 'method' => 'card', 'amount' => '8.000',
        'status' => 'success', 'softpos_auth_code' => 'AUTH123',
    ]);

    LoyaltyTransaction::factory()->for($ctx['company'], 'company')->earn(30)->create(['order_id' => $order->id]);
    LoyaltyTransaction::factory()->for($ctx['company'], 'company')->redeem(10)->create(['order_id' => $order->id]);

    return $order;
}

it('returns the full order detail', function (): void {
    $ctx = makeMerchantActor();
    $order = seedDetailedOrder($ctx);

    $res = $this->getJson("/api/orders/{$order->uuid}")->assertOk();

    expect($res->json('data.order.staff.name'))->toBe('Sam');
    expect($res->json('data.order.customer.name'))->toBe('Alice');
    expect($res->json('data.order.customer.phone'))->toBe('+96890000001');
    expect($res->json('data.order.plate_number'))->toBe('A12345');
    expect($res->json('data.order.note'))->toBe('Extra hot');
    expect($res->json('data.order.totals.grand_total'))->toBe('8.000');

    expect($res->json('data.items'))->toHaveCount(2);
    $latte = collect($res->json('data.items'))->firstWhere('product_name', 'Latte');
    expect($latte['addons'])->toHaveCount(1);
    expect($latte['addons'][0]['name'])->toBe('Oat milk');
    expect($latte['line_discount'])->toBe('1.000');
    expect($latte['discounts'][0]['name'])->toBe('Latte promo');

    expect($res->json('data.order_discounts'))->toHaveCount(1);
    expect($res->json('data.order_discounts.0.name'))->toBe('Happy Hour');

    expect($res->json('data.payments.0.method'))->toBe('card');
    expect($res->json('data.payments.0.softpos_auth_code'))->toBe('AUTH123');

    expect($res->json('data.loyalty.points_earned'))->toBe(30);
    expect($res->json('data.loyalty.points_redeemed'))->toBe(10);
});

it('404s on an unknown order uuid', function (): void {
    makeMerchantActor();
    $this->getJson('/api/orders/00000000-0000-0000-0000-000000000000')->assertNotFound();
});

it('does not leak another tenant order (404)', function (): void {
    makeMerchantActor();
    $foreign = Order::factory()->paid()->create(['grand_total' => '99.000']); // different company

    $this->getJson("/api/orders/{$foreign->uuid}")->assertNotFound();
});

it('is gated under reports.view', function (): void {
    $ctx = makeMerchantActor();
    $order = seedDetailedOrder($ctx);
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson("/api/orders/{$order->uuid}")->assertForbidden();
});

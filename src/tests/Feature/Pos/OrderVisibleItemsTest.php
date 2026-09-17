<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows only retained QR items in merchant detail and list without changing stored history or totals', function (string $status): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'source' => 'qr_web', 'status' => $status, 'opened_at' => '2026-06-15 10:00:00',
        'subtotal' => '3.800', 'tax_total' => '0.190', 'grand_total' => '3.990',
    ]);
    foreach (['Sweety' => '0.300', 'Coffee' => '2.000', 'Cake' => '1.500', 'Free item' => '0.000'] as $name => $price) {
        OrderItem::factory()->for($order, 'order')->create([
            'product_name_snapshot' => $name, 'qty' => '1.000', 'status' => 'open',
            'unit_price_snapshot' => $price, 'line_total' => $price,
        ]);
    }
    foreach (range(1, 5) as $index) {
        OrderItem::factory()->for($order, 'order')->create([
            'product_name_snapshot' => "Removed {$index}", 'qty' => '0.000', 'status' => 'void', 'line_total' => '0.000',
        ]);
    }
    OrderItem::factory()->for($order, 'order')->create(['qty' => '1.000', 'status' => 'void']);
    OrderItem::factory()->for($order, 'order')->create(['qty' => '0.000', 'status' => 'open']);
    $before = $order->items()->get()->toJson();

    $detail = $this->getJson("/api/orders/{$order->uuid}")->assertOk();
    expect(array_column($detail->json('data.items'), 'product_name'))->toBe(['Sweety', 'Coffee', 'Cake', 'Free item']);
    expect($detail->json('data.order.totals.grand_total'))->toBe('3.990');
    $list = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect(collect($list->json('data.rows'))->firstWhere('uuid', $order->uuid)['items_count'])->toBe(4);
    expect($order->items()->get()->toJson())->toBe($before);
})->with(['held', 'awaiting_payment', 'paid']);

it('retains the actual items of a fully voided order while hiding earlier zero-quantity edits', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'source' => 'qr_web', 'status' => 'void',
    ]);
    OrderItem::factory()->for($order, 'order')->create([
        'product_name_snapshot' => 'Voided sale item', 'qty' => '1.000', 'status' => 'void',
    ]);
    OrderItem::factory()->for($order, 'order')->create([
        'product_name_snapshot' => 'Earlier removed item', 'qty' => '0.000', 'status' => 'void',
    ]);
    $detail = $this->getJson("/api/orders/{$order->uuid}")->assertOk();
    expect(array_column($detail->json('data.items'), 'product_name'))->toBe(['Voided sale item']);
    expect($order->items()->count())->toBe(2);
});

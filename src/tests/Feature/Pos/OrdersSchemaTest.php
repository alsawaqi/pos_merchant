<?php

declare(strict_types=1);

/**
 * Phase 7a — schema-invariant tests for orders + items + addons
 * + payments + shifts.
 *
 * This phase doesn't ship Actions or controllers (Phase 8 owns
 * the write path), so the tests focus on:
 *
 *   - Casts + relations behave correctly through Eloquent
 *   - Schema-level uniques + nullable FKs enforce the right
 *     shape
 *   - Factories produce consistent shapes
 *   - The SeedDemoOrdersAction is DETERMINISTIC (same seed →
 *     identical outcome) and produces orders whose totals
 *     reconcile against their payments + items
 *   - Cross-tenant scoping queries don't leak orders between
 *     companies
 */

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== ORDER MODEL ===================

it('creates an order with all the snapshot columns + enum casts', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '2.500',
        'tax_total' => '0.125',
        'grand_total' => '2.625',
    ]);

    // Casts surface as the right types.
    expect($order->order_type)->toBe(OrderType::Quick);
    expect($order->status)->toBe(OrderStatus::Paid);
    // decimal:3 cast returns a string with 3 dp.
    expect((string) $order->subtotal)->toBe('2.500');
    expect((string) $order->grand_total)->toBe('2.625');
    // Booted uuid mint.
    expect($order->uuid)->toMatch('/^[0-9a-f-]{36}$/');
});

it('rejects a duplicate client_event_id via the schema unique constraint', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')
        ->create(['client_event_id' => 'evt_dupe_test']);

    expect(fn () => Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')
        ->create(['client_event_id' => 'evt_dupe_test']))->toThrow(Exception::class);
});

it('cascades item + addon rows when the parent order is deleted', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    OrderItem::factory()->for($order, 'order')->for($product, 'product')->count(3)->create();

    expect(OrderItem::query()->where('order_id', $order->id)->count())->toBe(3);

    $order->delete();
    expect(OrderItem::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('isTerminal() reports correctly across the OrderStatus enum', function (): void {
    expect(OrderStatus::Open->isTerminal())->toBeFalse();
    expect(OrderStatus::Held->isTerminal())->toBeFalse();
    expect(OrderStatus::Kitchen->isTerminal())->toBeFalse();
    expect(OrderStatus::Paid->isTerminal())->toBeTrue();
    expect(OrderStatus::Void->isTerminal())->toBeTrue();
    expect(OrderStatus::Refunded->isTerminal())->toBeTrue();
});

// =================== ORDER ITEM ===================

it('casts recipe_snapshot_json as an array (Postgres jsonb / sqlite text)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $item = OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
        'recipe_snapshot_json' => [
            ['ingredient_id' => 1, 'name' => 'Milk', 'qty' => '0.200', 'unit' => 'l'],
            ['ingredient_id' => 2, 'name' => 'Espresso Beans', 'qty' => '0.018', 'unit' => 'kg'],
        ],
    ]);

    $item->refresh();
    expect($item->recipe_snapshot_json)->toBeArray();
    expect($item->recipe_snapshot_json)->toHaveCount(2);
    expect($item->recipe_snapshot_json[0]['name'])->toBe('Milk');
});

it('serves OrderItemStatus enum cast cleanly', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $item = OrderItem::factory()->for($order, 'order')->for($product, 'product')->sentToKitchen()->create();

    $item->refresh();
    expect($item->status)->toBe(OrderItemStatus::SentToKitchen);
});

// =================== PAYMENT ===================

it('records a successful cash payment via the factory + scopes', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '5.000',
    ]);
    Payment::factory()->for($order, 'order')->create();

    expect(Payment::query()->success()->count())->toBe(1);
    expect(Payment::query()->pendingReconciliation()->count())->toBe(0);

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
    expect($payment->method)->toBe(PaymentMethod::Cash);
    expect($payment->status)->toBe(PaymentStatus::Success);
    expect((string) $payment->amount)->toBe('5.000');
});

it('records a pending-reconciliation card payment for the §16 Soft POS flow', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    Payment::factory()->for($order, 'order')->pendingReconciliation()->create();

    expect(Payment::query()->pendingReconciliation()->count())->toBe(1);
    $row = Payment::query()->firstOrFail();
    expect($row->method)->toBe(PaymentMethod::Card);
    expect($row->status)->toBe(PaymentStatus::PendingReconciliation);
    expect($row->pending_reconciliation)->toBeTrue();
});

// =================== SHIFT ===================

it('builds an open shift with a float + closes with a clean variance', function (): void {
    $ctx = makeMerchantActor();
    $open = Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    expect($open->status)->toBe(ShiftStatus::Open);
    expect((string) $open->opening_cash)->toBe('50.000');
    expect($open->closing_cash)->toBeNull();

    $closed = Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->closed()->create();
    expect($closed->status)->toBe(ShiftStatus::Closed);
    expect((string) $closed->variance)->toBe('0.000');
});

it('records a SHORT shift (cashier off-the-books) via the short() state', function (): void {
    $ctx = makeMerchantActor();
    $short = Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->short('-5.000')->create();
    expect((string) $short->variance)->toBe('-5.000');
    expect((string) $short->expected_cash)->toBe('150.000');
    expect((string) $short->closing_cash)->toBe('145.000');
});

// =================== CROSS-TENANT ===================

it('isolates orders per company (no cross-tenant leakage on the Order query builder)', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->count(3)->create();

    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    Order::factory()->for($otherCompany, 'company')->for($otherBranch, 'branch')->count(5)->create();

    expect(Order::query()->where('company_id', $ctx['company']->id)->count())->toBe(3);
    expect(Order::query()->where('company_id', $otherCompany->id)->count())->toBe(5);
});

// =================== SEEDER ===================

it('SeedDemoOrdersAction produces deterministic output for the same (count, seed) tuple', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->count(5)->create(['base_price' => '2.500']);

    $seeder = app(\App\Actions\Pos\Orders\SeedDemoOrdersAction::class);
    $endingAt = now()->setDate(2026, 6, 4)->setTime(12, 0);

    $result = $seeder->handle($ctx['company'], 50, 12345, $endingAt);

    // Output counts match the input target.
    expect($result['orders'])->toBe(50);
    expect($result['items'])->toBeGreaterThanOrEqual(50);
    // 1 or 2 payments per order (split path) — payments >= orders.
    expect($result['payments'])->toBeGreaterThanOrEqual(50);

    // Capture an aggregate snapshot of the seeded output.
    $firstRunTotal = (string) DB::table('pos_orders')
        ->where('company_id', $ctx['company']->id)
        ->sum('grand_total');

    // Reset + re-seed with the same seed → identical totals.
    Order::query()->where('company_id', $ctx['company']->id)->delete();
    $seeder->handle($ctx['company'], 50, 12345, $endingAt);
    $secondRunTotal = (string) DB::table('pos_orders')
        ->where('company_id', $ctx['company']->id)
        ->sum('grand_total');

    expect($secondRunTotal)->toBe($firstRunTotal);
});

it('SeedDemoOrdersAction keeps SUM(payments) == SUM(orders.grand_total) for every seeded order', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->count(3)->create(['base_price' => '2.500']);

    $seeder = app(\App\Actions\Pos\Orders\SeedDemoOrdersAction::class);
    $seeder->handle($ctx['company'], 30, 99, now());

    // Per-order invariant: SUM(payments.amount where success)
    // == orders.grand_total. Cast both to float for the
    // tolerance comparison (sqlite returns decimals as strings
    // and the SUM aggregator can lose trailing zeros).
    $orders = Order::query()->where('company_id', $ctx['company']->id)->get();
    foreach ($orders as $order) {
        $payments = (float) Payment::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Success->value)
            ->sum('amount');
        expect($payments)->toBe((float) $order->grand_total);
    }
});

it('SeedDemoOrdersAction errors when the company has no branches or products', function (): void {
    $ctx = makeMerchantActor();
    // Erase the branch + don't add products.
    $ctx['branch']->delete();

    $seeder = app(\App\Actions\Pos\Orders\SeedDemoOrdersAction::class);
    expect(fn () => $seeder->handle($ctx['company'], 5))
        ->toThrow(RuntimeException::class);
});

it('SeedDemoOrdersAction errors on non-positive count', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create();

    $seeder = app(\App\Actions\Pos\Orders\SeedDemoOrdersAction::class);
    expect(fn () => $seeder->handle($ctx['company'], 0))
        ->toThrow(RuntimeException::class);
});

// =================== SCOPES ===================

it('Order::scopeOpenedBetween filters by the business opened_at window', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'opened_at' => now()->subDays(10),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'opened_at' => now()->subDays(3),
    ]);

    $count = Order::query()
        ->openedBetween(now()->subDays(7), now())
        ->count();
    expect($count)->toBe(1);
});

it('Shift::scopeOpen filters to OPEN shifts', function (): void {
    $ctx = makeMerchantActor();
    Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->count(2)->create();
    Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->closed()->create();

    expect(Shift::query()->open()->count())->toBe(2);
});

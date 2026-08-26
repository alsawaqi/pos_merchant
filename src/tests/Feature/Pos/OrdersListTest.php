<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists the company orders in the date window, tenant-scoped, with totals', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];

    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create(['opened_at' => '2026-06-15 10:00:00', 'grand_total' => '5.000']);
    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create(['opened_at' => '2026-06-15 12:00:00', 'grand_total' => '3.000']);
    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->create(['opened_at' => '2026-05-01 12:00:00', 'grand_total' => '9.000']); // outside window
    Order::factory()->create(['opened_at' => '2026-06-15 12:00:00', 'grand_total' => '99.000']); // another company

    $res = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($res->json('data.rows'))->toHaveCount(2);
    expect($res->json('data.totals.count'))->toBe(2);
    expect($res->json('data.totals.grand_total'))->toBe('8.000'); // 5 + 3
});

it('hydrates QR-web awaiting-payment orders in the list and detail readers', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['opened_at' => '2026-06-15 10:00:00']);

    // Phase 1 is readers-only: write the future values directly in this
    // compatibility test rather than teaching any production writer about them.
    DB::table('pos_orders')->where('id', $order->id)->update([
        'status' => 'awaiting_payment',
        'source' => 'qr_web',
    ]);

    $list = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();
    $row = collect($list->json('data.rows'))->firstWhere('uuid', $order->uuid);

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('awaiting_payment')
        ->and($row['source'])->toBe('qr_web');

    $detail = $this->getJson("/api/orders/{$order->uuid}")
        ->assertOk();

    expect($detail->json('data.order.status'))->toBe('awaiting_payment')
        ->and($detail->json('data.order.source'))->toBe('qr_web');
});

it('carries the per-leg tender summary so a split reads off the list', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create(['opened_at' => '2026-06-15 10:00:00', 'grand_total' => '10.000']);
    // Half cash / half card split + a failed attempt that must not appear.
    foreach ([['cash', '5.000', 'success'], ['card', '5.000', 'success'], ['card', '5.000', 'failed']] as [$method, $amount, $status]) {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'method' => $method,
            'amount' => $amount, 'status' => $status, 'pending_reconciliation' => false,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $row = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows.0');

    expect($row['tenders'])->toHaveCount(2)
        ->and($row['tenders'][0]['method'])->toBe('cash')
        ->and($row['tenders'][1]['method'])->toBe('card')
        ->and($row['tenders'][1]['amount'])->toBe('5.000');
});

it('filters orders by branch', function (): void {
    $ctx = makeMerchantActor();
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    Order::factory()->for($ctx['company'], 'company')->for($b1, 'branch')->create(['opened_at' => '2026-06-15 10:00:00']);
    Order::factory()->for($ctx['company'], 'company')->for($b2, 'branch')->create(['opened_at' => '2026-06-15 11:00:00']);

    $res = $this->getJson("/api/orders?date_from=2026-06-01&date_to=2026-06-30&branch_ids[]={$b1->id}")->assertOk();

    expect($res->json('data.rows'))->toHaveCount(1);
    expect($res->json('data.rows.0.branch_id'))->toBe($b1->id);
});

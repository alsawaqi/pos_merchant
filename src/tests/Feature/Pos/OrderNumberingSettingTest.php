<?php

declare(strict_types=1);

/**
 * P-F8 — order numbering policy (how POS order numbers look: prefix +
 * zero-padded counter, per-branch vs company-wide sequence, optional
 * daily reset).
 *
 *   GET/PUT /api/settings/order-numbering  (orders.cancel)
 *
 * Persisted to pos_company_settings (key order_numbering); pos_api emits it
 * in /device/config and allocates numbers on POST /device/orders/next-number.
 * Default (no row) = disabled with the standard shape.
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to disabled with the standard shape when no policy is set', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/order-numbering')->assertOk();

    expect($res->json('data'))->toBe([
        'enabled' => false,
        'prefix' => '',
        'pad' => 4,
        'scope' => 'branch',
        'daily_reset' => false,
    ]);
});

it('round-trips the order numbering policy', function (): void {
    $ctx = makeMerchantActor();

    $payload = [
        'enabled' => true,
        'prefix' => 'KLD-',
        'pad' => 4,
        'scope' => 'company',
        'daily_reset' => true,
    ];

    $res = $this->putJson('/api/settings/order-numbering', $payload)->assertOk();
    expect($res->json('data'))->toBe($payload);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'order_numbering')
        ->firstOrFail();
    expect($row->value)->toBe($payload);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/order-numbering')->json('data'))->toBe($payload);
});

it('accepts an empty prefix', function (): void {
    makeMerchantActor();

    $res = $this->putJson('/api/settings/order-numbering', [
        'enabled' => true,
        'prefix' => '',
        'pad' => 3,
        'scope' => 'branch',
        'daily_reset' => false,
    ])->assertOk();

    expect($res->json('data.prefix'))->toBe('');
    expect($res->json('data.pad'))->toBe(3);
});

it('rejects a bad scope', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/order-numbering', [
        'enabled' => true, 'prefix' => 'A-', 'pad' => 4, 'scope' => 'global', 'daily_reset' => false,
    ])->assertStatus(422);
});

it('rejects an out-of-range pad', function (): void {
    makeMerchantActor();

    foreach ([2, 7, 'four'] as $pad) {
        $this->putJson('/api/settings/order-numbering', [
            'enabled' => true, 'prefix' => 'A-', 'pad' => $pad, 'scope' => 'branch', 'daily_reset' => false,
        ])->assertStatus(422);
    }
});

it('rejects an over-long prefix', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/order-numbering', [
        'enabled' => true, 'prefix' => 'TOO-LONG-1', 'pad' => 4, 'scope' => 'branch', 'daily_reset' => false,
    ])->assertStatus(422);
});

it('audits the policy change', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/order-numbering', [
        'enabled' => true, 'prefix' => 'KLD-', 'pad' => 4, 'scope' => 'branch', 'daily_reset' => false,
    ])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'company_id' => $ctx['company']->id,
        'event' => 'settings.order_numbering.updated',
    ]);
});

it('gates the policy behind orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/settings/order-numbering')->assertForbidden();
    $this->putJson('/api/settings/order-numbering', [
        'enabled' => true, 'prefix' => '', 'pad' => 4, 'scope' => 'branch', 'daily_reset' => false,
    ])->assertForbidden();
});

it('does not leak another company policy', function (): void {
    $ctx = makeMerchantActor();
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id + 999,
        'key' => 'order_numbering',
        'value' => ['enabled' => true, 'prefix' => 'X-', 'pad' => 5, 'scope' => 'company', 'daily_reset' => true],
    ]);

    expect($this->getJson('/api/settings/order-numbering')->json('data.enabled'))->toBeFalse();
});

it('surfaces receipt_number on the orders list and detail', function (): void {
    $ctx = makeMerchantActor();

    $numbered = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()
        ->create(['opened_at' => '2026-06-15 10:00:00', 'receipt_number' => 'KLD-0042']);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()
        ->create(['opened_at' => '2026-06-15 11:00:00']); // unnumbered (offline / disabled)

    $rows = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->json('data.rows');
    $byUuid = collect($rows)->keyBy('uuid');
    expect($byUuid[$numbered->uuid]['receipt_number'])->toBe('KLD-0042');
    expect($byUuid->except($numbered->uuid)->first()['receipt_number'])->toBeNull();

    expect($this->getJson("/api/orders/{$numbered->uuid}")->assertOk()->json('data.order.receipt_number'))
        ->toBe('KLD-0042');
});

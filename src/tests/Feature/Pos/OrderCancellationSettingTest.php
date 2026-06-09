<?php

declare(strict_types=1);

/**
 * v2 #14 — order cancellation policy (which staff positions may cancel a
 * completed order at the POS).
 *
 *   GET/PUT /api/settings/order-cancellation  (orders.cancel)
 *
 * Persisted to pos_company_settings (key order_cancel_positions); pos_api emits
 * it in /device/config and the device enforces it. Default (no row) = managers.
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to managers-only when no policy is set', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/order-cancellation')->assertOk();

    expect($res->json('data.positions'))->toBe(['manager']);
    expect($res->json('data.available_positions'))->toContain('cashier', 'manager', 'supervisor');
});

it('sets the allowed cancel positions', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->putJson('/api/settings/order-cancellation', [
        'positions' => ['manager', 'supervisor'],
    ])->assertOk();

    expect($res->json('data.positions'))->toBe(['manager', 'supervisor']);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'order_cancel_positions')
        ->firstOrFail();
    expect($row->value)->toBe(['manager', 'supervisor']);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/order-cancellation')->json('data.positions'))
        ->toBe(['manager', 'supervisor']);
});

it('rejects an unknown position', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/order-cancellation', [
        'positions' => ['manager', 'wizard'],
    ])->assertStatus(422);
});

it('rejects an empty positions list', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/order-cancellation', ['positions' => []])
        ->assertStatus(422);
});

it('gates the policy behind orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/settings/order-cancellation')->assertForbidden();
    $this->putJson('/api/settings/order-cancellation', ['positions' => ['manager']])
        ->assertForbidden();
});

it('does not leak another company policy', function (): void {
    $ctx = makeMerchantActor();
    // A foreign company's policy must not surface for this actor.
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id + 999,
        'key' => 'order_cancel_positions',
        'value' => ['cashier'],
    ]);

    expect($this->getJson('/api/settings/order-cancellation')->json('data.positions'))
        ->toBe(['manager']);
});

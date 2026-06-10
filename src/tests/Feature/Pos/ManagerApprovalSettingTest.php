<?php

declare(strict_types=1);

/**
 * P-F1 — manager approval policy (which staff positions may authorize
 * sensitive POS actions — comps, cancellations, gifts — by PIN, the
 * manager-fingerprint fallback).
 *
 *   GET/PUT /api/settings/manager-approval  (orders.cancel)
 *
 * Persisted to pos_company_settings (key manager_approval_positions); pos_api
 * emits it in /device/config and verifies PINs against it on
 * /device/auth/verify-manager-pin. Default (no row) = managers.
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to managers-only when no policy is set', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/manager-approval')->assertOk();

    expect($res->json('data.positions'))->toBe(['manager']);
    expect($res->json('data.available_positions'))->toContain('cashier', 'manager', 'supervisor');
});

it('sets the approval positions', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->putJson('/api/settings/manager-approval', [
        'positions' => ['manager', 'supervisor'],
    ])->assertOk();

    expect($res->json('data.positions'))->toBe(['manager', 'supervisor']);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'manager_approval_positions')
        ->firstOrFail();
    expect($row->value)->toBe(['manager', 'supervisor']);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/manager-approval')->json('data.positions'))
        ->toBe(['manager', 'supervisor']);
});

it('rejects an unknown position', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/manager-approval', [
        'positions' => ['manager', 'wizard'],
    ])->assertStatus(422);
});

it('rejects an empty positions list', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/manager-approval', ['positions' => []])
        ->assertStatus(422);
});

it('gates the policy behind orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/settings/manager-approval')->assertForbidden();
    $this->putJson('/api/settings/manager-approval', ['positions' => ['manager']])
        ->assertForbidden();
});

it('does not leak another company policy', function (): void {
    $ctx = makeMerchantActor();
    // A foreign company's policy must not surface for this actor.
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id + 999,
        'key' => 'manager_approval_positions',
        'value' => ['cashier'],
    ]);

    expect($this->getJson('/api/settings/manager-approval')->json('data.positions'))
        ->toBe(['manager']);
});

it('keeps the two position policies independent', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/order-cancellation', ['positions' => ['cashier', 'manager']])->assertOk();
    $this->putJson('/api/settings/manager-approval', ['positions' => ['supervisor']])->assertOk();

    expect($this->getJson('/api/settings/order-cancellation')->json('data.positions'))
        ->toBe(['cashier', 'manager']);
    expect($this->getJson('/api/settings/manager-approval')->json('data.positions'))
        ->toBe(['supervisor']);
    expect(CompanySetting::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

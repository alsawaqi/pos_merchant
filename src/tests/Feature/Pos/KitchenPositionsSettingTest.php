<?php

declare(strict_types=1);

/**
 * Device Kitchen-section access policy (which staff positions may open the
 * Kitchen production screen on the POS device) — part of the POS Settings hub.
 *
 *   GET/PUT /api/settings/kitchen-positions  (orders.cancel)
 *
 * Persisted to pos_company_settings (key kitchen_positions); pos_api emits it in
 * /device/config and the DEVICE gates its Kitchen screen on it. The 'kitchen'
 * role ALWAYS has access (enforced in pos_api), so it is never a selectable
 * choice here — this setting only lists the OTHER positions that should also get
 * access. Saving an empty list is valid (kitchen-role-only). Default (no row) =
 * empty (no managers-only fallback).
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to no extra positions and never offers the kitchen role as a choice', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/kitchen-positions')->assertOk();

    // No row yet: no extra roles selected (the kitchen role is implicit).
    expect($res->json('data.positions'))->toBe([]);
    // 'kitchen' is never a checkbox (it always has access); the others are.
    expect($res->json('data.available_positions'))->toContain('cashier', 'manager', 'supervisor', 'waiter');
    expect($res->json('data.available_positions'))->not->toContain('kitchen');
});

it('sets the extra kitchen positions', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->putJson('/api/settings/kitchen-positions', [
        'positions' => ['manager', 'cashier'],
    ])->assertOk();

    expect($res->json('data.positions'))->toBe(['manager', 'cashier']);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'kitchen_positions')
        ->firstOrFail();
    expect($row->value)->toBe(['manager', 'cashier']);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))
        ->toBe(['manager', 'cashier']);
});

it('accepts an empty positions list (kitchen-role-only)', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', ['positions' => []])->assertOk();

    // Persisted as an explicit empty list and read back as empty — NOT defaulted
    // to managers (the kitchen role still has access, enforced server-side).
    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'kitchen_positions')
        ->firstOrFail();
    expect($row->value)->toBe([]);
    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))->toBe([]);
});

it('rejects the kitchen role as a selectable position (it is always implicit)', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['kitchen']])
        ->assertStatus(422);
});

it('rejects an unknown position', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', [
        'positions' => ['manager', 'wizard'],
    ])->assertStatus(422);
});

it('gates the policy behind orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/settings/kitchen-positions')->assertForbidden();
    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['manager']])
        ->assertForbidden();
});

it('does not leak another company policy', function (): void {
    $ctx = makeMerchantActor();
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id + 999,
        'key' => 'kitchen_positions',
        'value' => ['cashier'],
    ]);

    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))
        ->toBe([]);
});

it('stays independent from the reports policy', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/reports-positions', ['positions' => ['supervisor']])->assertOk();
    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['manager']])->assertOk();

    expect($this->getJson('/api/settings/reports-positions')->json('data.positions'))
        ->toBe(['supervisor']);
    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))
        ->toBe(['manager']);
    expect(CompanySetting::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

it('audits the policy change', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['manager']])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'company_id' => $ctx['company']->id,
        'event' => 'settings.kitchen_positions.updated',
    ]);
});

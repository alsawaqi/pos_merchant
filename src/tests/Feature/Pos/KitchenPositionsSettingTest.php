<?php

declare(strict_types=1);

/**
 * P-G1 — device Kitchen-section access policy (which staff positions may
 * open the Kitchen production screen on the POS device).
 *
 *   GET/PUT /api/settings/kitchen-positions  (orders.cancel)
 *
 * Persisted to pos_company_settings (key kitchen_positions); pos_api emits
 * it in /device/config and the DEVICE gates its Kitchen screen on it.
 * Default (no row) = managers.
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to managers-only when no policy is set', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/kitchen-positions')->assertOk();

    expect($res->json('data.positions'))->toBe(['manager']);
    expect($res->json('data.available_positions'))->toContain('cashier', 'kitchen', 'manager');
});

it('sets the kitchen positions', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->putJson('/api/settings/kitchen-positions', [
        'positions' => ['manager', 'kitchen'],
    ])->assertOk();

    expect($res->json('data.positions'))->toBe(['manager', 'kitchen']);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'kitchen_positions')
        ->firstOrFail();
    expect($row->value)->toBe(['manager', 'kitchen']);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))
        ->toBe(['manager', 'kitchen']);
});

it('rejects an unknown position', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', [
        'positions' => ['manager', 'wizard'],
    ])->assertStatus(422);
});

it('rejects an empty positions list', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', ['positions' => []])
        ->assertStatus(422);
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
        ->toBe(['manager']);
});

it('stays independent from the reports policy', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/reports-positions', ['positions' => ['supervisor']])->assertOk();
    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['kitchen', 'manager']])->assertOk();

    expect($this->getJson('/api/settings/reports-positions')->json('data.positions'))
        ->toBe(['supervisor']);
    expect($this->getJson('/api/settings/kitchen-positions')->json('data.positions'))
        ->toBe(['kitchen', 'manager']);
    expect(CompanySetting::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

it('audits the policy change', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/kitchen-positions', ['positions' => ['manager', 'kitchen']])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'company_id' => $ctx['company']->id,
        'event' => 'settings.kitchen_positions.updated',
    ]);
});

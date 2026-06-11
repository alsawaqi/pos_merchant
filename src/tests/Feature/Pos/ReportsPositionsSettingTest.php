<?php

declare(strict_types=1);

/**
 * P-F6 — device reports access policy (which staff positions may open the
 * Reports dashboard on the POS device).
 *
 *   GET/PUT /api/settings/reports-positions  (orders.cancel)
 *
 * Persisted to pos_company_settings (key reports_positions); pos_api emits
 * it in /device/config and the DEVICE gates its Reports screen on it.
 * Default (no row) = managers.
 */

use App\Enums\MerchantRole;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to managers-only when no policy is set', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/settings/reports-positions')->assertOk();

    expect($res->json('data.positions'))->toBe(['manager']);
    expect($res->json('data.available_positions'))->toContain('cashier', 'manager', 'supervisor');
});

it('sets the reports positions', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->putJson('/api/settings/reports-positions', [
        'positions' => ['manager', 'supervisor'],
    ])->assertOk();

    expect($res->json('data.positions'))->toBe(['manager', 'supervisor']);

    $row = CompanySetting::query()
        ->where('company_id', $ctx['company']->id)
        ->where('key', 'reports_positions')
        ->firstOrFail();
    expect($row->value)->toBe(['manager', 'supervisor']);

    // And it reads back on the next GET.
    expect($this->getJson('/api/settings/reports-positions')->json('data.positions'))
        ->toBe(['manager', 'supervisor']);
});

it('rejects an unknown position', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/reports-positions', [
        'positions' => ['manager', 'wizard'],
    ])->assertStatus(422);
});

it('rejects an empty positions list', function (): void {
    makeMerchantActor();

    $this->putJson('/api/settings/reports-positions', ['positions' => []])
        ->assertStatus(422);
});

it('gates the policy behind orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/settings/reports-positions')->assertForbidden();
    $this->putJson('/api/settings/reports-positions', ['positions' => ['manager']])
        ->assertForbidden();
});

it('does not leak another company policy', function (): void {
    $ctx = makeMerchantActor();
    // A foreign company's policy must not surface for this actor.
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id + 999,
        'key' => 'reports_positions',
        'value' => ['cashier'],
    ]);

    expect($this->getJson('/api/settings/reports-positions')->json('data.positions'))
        ->toBe(['manager']);
});

it('keeps the three position policies independent', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/order-cancellation', ['positions' => ['cashier', 'manager']])->assertOk();
    $this->putJson('/api/settings/manager-approval', ['positions' => ['supervisor']])->assertOk();
    $this->putJson('/api/settings/reports-positions', ['positions' => ['waiter', 'manager']])->assertOk();

    expect($this->getJson('/api/settings/order-cancellation')->json('data.positions'))
        ->toBe(['cashier', 'manager']);
    expect($this->getJson('/api/settings/manager-approval')->json('data.positions'))
        ->toBe(['supervisor']);
    expect($this->getJson('/api/settings/reports-positions')->json('data.positions'))
        ->toBe(['waiter', 'manager']);
    expect(CompanySetting::query()->where('company_id', $ctx['company']->id)->count())->toBe(3);
});

it('audits the policy change', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/reports-positions', ['positions' => ['manager', 'cashier']])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'company_id' => $ctx['company']->id,
        'event' => 'settings.reports_positions.updated',
    ]);
});

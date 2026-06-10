<?php

declare(strict_types=1);

/**
 * Phase B (Additions §1.2) — void + comp reason code CRUD coverage.
 *
 * Covers:
 *   - Index lazily seeds the doc's default code lists (9 void / 6 comp)
 *   - Create mints an immutable slug code; duplicate names → 422
 *   - Update flips flags / renames WITHOUT changing the code
 *   - Delete soft-deletes; cross-tenant rows are invisible (404)
 *   - Gating: orders.cancel required (Viewer → 403)
 */

use App\Enums\MerchantRole;
use App\Models\CompReason;
use App\Models\VoidReason;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== DEFAULT SEEDING ===================

it('seeds the default void + comp reasons on first index', function (): void {
    makeMerchantActor();

    $void = $this->getJson('/api/void-reasons')->assertOk()->json('data');
    expect($void)->toHaveCount(9);
    expect(collect($void)->firstWhere('code', 'quality_issue')['affects_inventory'])->toBeTrue();
    expect(collect($void)->firstWhere('code', 'wrong_order_entry')['affects_inventory'])->toBeFalse();

    $comp = $this->getJson('/api/comp-reasons')->assertOk()->json('data');
    expect($comp)->toHaveCount(6);
    expect(collect($comp)->firstWhere('code', 'staff_meal'))->not->toBeNull();
});

// =================== CREATE / UPDATE ===================

it('creates a void reason with a minted immutable code and updates flags', function (): void {
    $ctx = makeMerchantActor();

    $created = $this->postJson('/api/void-reasons', [
        'name' => 'Spilled Drink',
        'affects_inventory' => true,
        'requires_manager' => false,
    ])->assertCreated()->json('data');

    expect($created['code'])->toBe('spilled_drink');
    expect($created['affects_inventory'])->toBeTrue();

    // Rename + flag flip — code stays.
    $updated = $this->patchJson("/api/void-reasons/{$created['uuid']}", [
        'name' => 'Spilled Beverage',
        'requires_manager' => true,
    ])->assertOk()->json('data');
    expect($updated['code'])->toBe('spilled_drink');
    expect($updated['name'])->toBe('Spilled Beverage');
    expect($updated['requires_manager'])->toBeTrue();

    // Duplicate name → friendly 422.
    $this->postJson('/api/void-reasons', ['name' => 'Spilled Beverage'])->assertUnprocessable();
});

it('creates a comp reason with a max_amount cap and clears it on update', function (): void {
    makeMerchantActor();

    $created = $this->postJson('/api/comp-reasons', [
        'name' => 'Birthday Treat',
        'max_amount' => '5.000',
    ])->assertCreated()->json('data');
    expect($created['max_amount'])->toBe('5.000');

    $updated = $this->patchJson("/api/comp-reasons/{$created['uuid']}", [
        'max_amount' => null,
    ])->assertOk()->json('data');
    expect($updated['max_amount'])->toBeNull();
});

// =================== DELETE + TENANCY + GATES ===================

it('soft-deletes a reason and hides cross-tenant rows', function (): void {
    $ctx = makeMerchantActor();
    $other = makeMerchantActor();
    app(\App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);

    $mine = VoidReason::query()->create([
        'company_id' => $ctx['company']->id, 'code' => 'mine', 'name' => 'Mine',
    ]);
    $foreign = CompReason::query()->create([
        'company_id' => $other['company']->id, 'code' => 'foreign', 'name' => 'Foreign',
    ]);

    $this->deleteJson("/api/void-reasons/{$mine->uuid}")->assertNoContent();
    expect(VoidReason::query()->find($mine->id))->toBeNull();
    expect(VoidReason::withTrashed()->find($mine->id))->not->toBeNull();

    $this->deleteJson("/api/comp-reasons/{$foreign->uuid}")->assertNotFound();
});

it('blocks reason management without orders.cancel', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/void-reasons')->assertForbidden();
    $this->postJson('/api/comp-reasons', ['name' => 'X'])->assertForbidden();
});

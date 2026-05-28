<?php

declare(strict_types=1);

/**
 * Phase 7b-5 — Round-Up Donation Report coverage (blueprint §5.11.9).
 *
 * This report is STUBBED in Phase 7b. See the Action docblock for
 * why: donation infrastructure (round-up config, charity directory,
 * donation_amount column on pos_orders) lands in Phase 9.
 *
 * These tests verify:
 *   - The endpoint exists and is gated under reports.view
 *   - The payload shape is what the Phase 7b-6 UI expects, even
 *     while the numbers are all zero
 *   - The Phase 9 stub note is exposed so callers see when the
 *     real numbers ship
 */

use App\Enums\MerchantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a zeroed payload with the expected shape', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/round-up-donation?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_raised'))->toBe('0.000');
    expect($response->json('data.headline.donation_count'))->toBe(0);
    // PHP 0.0 -> JSON 0 (int) on this stack; coerce both sides.
    expect((float) $response->json('data.headline.opt_in_rate_pct'))->toBe(0.0);
    expect($response->json('data.by_charity'))->toBe([]);
    expect($response->json('data.by_branch'))->toBe([]);
});

it('exposes the Phase 9 donation stub note', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/round-up-donation?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data._phase.donation_stub'))->toContain('Phase 9');
});

it('is gated under reports.view', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/reports/round-up-donation?date_from=2026-06-01&date_to=2026-06-30')
        ->assertForbidden();
});

it('returns 422 when date_from / date_to are missing', function (): void {
    makeMerchantActor();

    $this->getJson('/api/reports/round-up-donation?date_to=2026-06-30')
        ->assertStatus(422)->assertJsonValidationErrors(['date_from']);
});

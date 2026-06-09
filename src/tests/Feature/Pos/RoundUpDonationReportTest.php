<?php

declare(strict_types=1);

/**
 * v2 #18 — Round-Up Donation Report (blueprint §5.11.9).
 *
 * The round-up is live: aggregates pos_roundup_donations for the company over a
 * window — total raised (success), pending/failed counts, by-branch, by-status.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedRoundup(int $companyId, int $branchId, string $amount, string $status, string $occurredAt = '2026-06-10 10:00:00'): void
{
    DB::table('pos_roundup_donations')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'amount' => $amount,
        'status' => $status,
        'source' => 'pos_roundup',
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);
}

it('aggregates raised, pending and failed donations for the window', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    seedRoundup($cid, 10, '0.200', 'success');
    seedRoundup($cid, 10, '0.150', 'success');
    seedRoundup($cid, 20, '0.300', 'success');
    seedRoundup($cid, 10, '0.100', 'pending');
    seedRoundup($cid, 10, '0.050', 'fail');
    // Outside window — excluded.
    seedRoundup($cid, 10, '9.000', 'success', '2026-05-01 10:00:00');

    $data = $this->getJson('/api/reports/round-up-donation?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');

    expect($data['headline']['total_raised'])->toBe('0.650'); // 0.200+0.150+0.300
    expect($data['headline']['donation_count'])->toBe(3);
    expect($data['headline']['pending_count'])->toBe(1);
    expect($data['headline']['failed_count'])->toBe(1);

    // by_branch (success only), biggest first → branch 20 (0.300) then 10 (0.350)?
    // branch 10 success = 0.200 + 0.150 = 0.350 → branch 10 first.
    expect($data['by_branch'][0]['branch_id'])->toBe(10);
    expect($data['by_branch'][0]['total_raised'])->toBe('0.350');
    expect($data['by_branch'][0]['donation_count'])->toBe(2);
    expect($data['by_branch'][1]['branch_id'])->toBe(20);

    $byStatus = collect($data['by_status'])->keyBy('status');
    expect($byStatus['success']['count'])->toBe(3);
    expect($byStatus['pending']['count'])->toBe(1);
    expect($byStatus['fail']['count'])->toBe(1);
});

it('does not leak another company donations', function (): void {
    $ctx = makeMerchantActor();
    seedRoundup($ctx['company']->id, 10, '0.200', 'success');
    seedRoundup(Company::factory()->create()->id, 77, '5.000', 'success');

    $data = $this->getJson('/api/reports/round-up-donation?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');

    expect($data['headline']['total_raised'])->toBe('0.200');
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

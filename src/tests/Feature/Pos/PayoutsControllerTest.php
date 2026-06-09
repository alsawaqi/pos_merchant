<?php

declare(strict_types=1);

/**
 * v2 #17 (Phase B) — merchant payout history (read-only).
 *
 *   GET /api/payouts[?status=]  (reports.view) — this company's payouts only.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedPayout(int $companyId, string $status, string $net, string $createdAt): void
{
    DB::table('pos_payouts')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'period_from' => '2026-06-01 00:00:00',
        'period_to' => '2026-06-30 23:59:59',
        'status' => $status,
        'gross_amount' => '10.000',
        'platform_amount' => '0.200',
        'bank_amount' => '0.090',
        'other_amount' => '0.000',
        'net_amount' => $net,
        'sales_count' => 3,
        'reference' => $status === 'paid' ? 'REF-1' : null,
        'paid_at' => $status === 'paid' ? $createdAt : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('lists the company own payouts, newest first', function (): void {
    $ctx = makeMerchantActor();
    seedPayout($ctx['company']->id, 'paid', '9.710', '2026-06-01 10:00:00');
    seedPayout($ctx['company']->id, 'pending', '4.000', '2026-07-01 10:00:00');
    // Foreign company — must not leak.
    seedPayout(Company::factory()->create()->id, 'pending', '99.000', '2026-07-02 10:00:00');

    $rows = $this->getJson('/api/payouts')->assertOk()->json('data');

    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBe('pending');     // newest first
    expect($rows[0]['net_amount'])->toBe('4.000');
    expect($rows[1]['status'])->toBe('paid');
    expect($rows[1]['net_amount'])->toBe('9.710');
    expect($rows[1]['reference'])->toBe('REF-1');
});

it('filters payouts by status', function (): void {
    $ctx = makeMerchantActor();
    seedPayout($ctx['company']->id, 'paid', '9.710', '2026-06-01 10:00:00');
    seedPayout($ctx['company']->id, 'pending', '4.000', '2026-07-01 10:00:00');

    $rows = $this->getJson('/api/payouts?status=paid')->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['status'])->toBe('paid');
});

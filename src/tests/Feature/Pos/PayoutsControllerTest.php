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

/** A payout with claimed sales (merchant rows carry payout_id) across branches. */
function seedClaimedSale(int $companyId, int $orderId, int $branchId, string $platform, string $bank, string $merchant, int $payoutId): void
{
    $gross = number_format((float) $platform + (float) $bank + (float) $merchant, 3, '.', '');
    $sort = 0;
    foreach (['platform' => $platform, 'bank' => $bank, 'merchant' => $merchant] as $party => $amount) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'device_id' => 1,
            'order_id' => $orderId, 'party_type' => $party, 'party_label' => ucfirst($party), 'percent' => 0,
            'gross_amount' => $gross, 'commission_amount' => $amount, 'sort_order' => $sort++,
            'payout_id' => $party === 'merchant' ? $payoutId : null,
            'occurred_at' => '2026-06-12 10:00:00', 'created_at' => '2026-06-12 10:00:00', 'updated_at' => '2026-06-12 10:00:00',
        ]);
    }
}

it('returns this company payout per-branch breakdown', function (): void {
    $ctx = makeMerchantActor();
    $main = $ctx['branch'];
    $mall = \App\Models\Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Mall']);

    $uuid = (string) Str::uuid();
    $payoutId = DB::table('pos_payouts')->insertGetId([
        'uuid' => $uuid, 'company_id' => $ctx['company']->id,
        'period_from' => '2026-06-01 00:00:00', 'period_to' => '2026-06-30 23:59:59', 'status' => 'pending',
        'gross_amount' => '5.000', 'platform_amount' => '0.100', 'bank_amount' => '0.090',
        'other_amount' => '0.000', 'net_amount' => '4.810', 'sales_count' => 2,
        'created_at' => '2026-06-30 10:00:00', 'updated_at' => '2026-06-30 10:00:00',
    ]);
    seedClaimedSale($ctx['company']->id, 1, $main->id, '0.060', '0.090', '2.850', $payoutId);
    seedClaimedSale($ctx['company']->id, 2, $mall->id, '0.040', '0.000', '1.960', $payoutId);

    $lines = $this->getJson("/api/payouts/{$uuid}/lines")->assertOk()->json('data');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['branch_name'])->toBe($main->name)  // sorted by net desc → 2.850 first
        ->and($lines[0]['merchant_net'])->toBe('2.850')
        ->and($lines[0]['bank'])->toBe('0.090')
        ->and($lines[0]['num_sales'])->toBe(1)
        ->and($lines[1]['branch_name'])->toBe('Mall')
        ->and($lines[1]['merchant_net'])->toBe('1.960');
});

it('404s on another company payout lines (tenant scope)', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $uuid = (string) Str::uuid();
    DB::table('pos_payouts')->insert([
        'uuid' => $uuid, 'company_id' => $other->id,
        'period_from' => '2026-06-01 00:00:00', 'period_to' => '2026-06-30 23:59:59', 'status' => 'pending',
        'gross_amount' => '5.000', 'platform_amount' => '0.100', 'bank_amount' => '0.000',
        'other_amount' => '0.000', 'net_amount' => '4.900', 'sales_count' => 1,
        'created_at' => '2026-06-30 10:00:00', 'updated_at' => '2026-06-30 10:00:00',
    ]);

    $this->getJson("/api/payouts/{$uuid}/lines")->assertNotFound();
});

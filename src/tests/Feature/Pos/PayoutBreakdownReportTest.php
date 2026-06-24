<?php

declare(strict_types=1);

/**
 * v2 #17 (Phase A) — merchant payout / commission breakdown report.
 *
 *   GET /api/reports/payouts?date_from=&date_to=  (reports.view)
 *
 * Aggregates pos_sale_commissions for the actor's company over the window:
 * gross = Σ all parties; platform/bank/other = each take; merchant_net = the
 * merchant residual. Window-bounded (occurred_at), tenant-scoped, per-branch.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Insert one party row of a sale's commission breakdown. */
function commissionRow(int $companyId, int $orderId, int $branchId, string $party, string $amount, string $gross, int $sortOrder, string $occurredAt): void
{
    DB::table('pos_sale_commissions')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'device_id' => 1,
        'order_id' => $orderId,
        'party_type' => $party,
        'party_label' => ucfirst($party),
        'percent' => 0,
        'gross_amount' => $gross,
        'commission_amount' => $amount,
        'sort_order' => $sortOrder,
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);
}

/** Seed a full sale breakdown (platform + bank + merchant rows) for a company. */
function seedSale(int $companyId, int $orderId, int $branchId, string $platform, string $bank, string $merchant, string $occurredAt = '2026-06-10 10:00:00'): void
{
    $gross = number_format((float) $platform + (float) $bank + (float) $merchant, 3, '.', '');
    commissionRow($companyId, $orderId, $branchId, 'platform', $platform, $gross, 0, $occurredAt);
    commissionRow($companyId, $orderId, $branchId, 'bank', $bank, $gross, 1, $occurredAt);
    commissionRow($companyId, $orderId, $branchId, 'merchant', $merchant, $gross, 2, $occurredAt);
}

it('aggregates the commission breakdown by party for the window', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    seedSale($cid, 1, 10, '0.060', '0.090', '2.850'); // gross 3.000
    seedSale($cid, 2, 10, '0.040', '0.000', '1.960'); // gross 2.000

    $res = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    $h = $res->json('data.headline');

    expect($h['gross'])->toBe('5.000');
    expect($h['platform'])->toBe('0.100');
    expect($h['bank'])->toBe('0.090');
    expect($h['other'])->toBe('0.000');
    expect($h['merchant_net'])->toBe('4.810');
    expect($h['num_sales'])->toBe(2);
});

it('excludes commissions outside the date window', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    seedSale($cid, 1, 10, '0.060', '0.090', '2.850', '2026-06-10 10:00:00'); // in
    seedSale($cid, 2, 10, '1.000', '0.000', '9.000', '2026-05-01 10:00:00'); // out

    $h = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data.headline');

    expect($h['gross'])->toBe('3.000');
    expect($h['num_sales'])->toBe(1);
});

it('does not leak another company commissions', function (): void {
    $ctx = makeMerchantActor();
    seedSale($ctx['company']->id, 1, 10, '0.060', '0.090', '2.850');
    seedSale(Company::factory()->create()->id, 99, 77, '5.000', '0.000', '5.000'); // foreign

    $h = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data.headline');

    expect($h['gross'])->toBe('3.000');
    expect($h['merchant_net'])->toBe('2.850');
});

it('shows the SETTLED net + estimate alongside, once a sale is reconciled', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    seedSale($cid, 1, 10, '0.060', '0.090', '2.850'); // estimate: gross 3.000

    // Reconciled: actual bank 0.150 (> est 0.090) → merchant absorbs the
    // 0.060 variance (2.790). All party rows of the order get is_settled.
    DB::table('pos_sale_commissions')->where('order_id', 1)->update(['is_settled' => true]);
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'bank')->update(['settled_amount' => '0.150']);
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'merchant')->update(['settled_amount' => '2.790']);
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'platform')->update(['settled_amount' => '0.060']);

    $res = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    $h = $res->json('data.headline');

    expect($h['merchant_net'])->toBe('2.790')              // settled
        ->and($h['merchant_net_estimated'])->toBe('2.850') // estimate, for comparison
        ->and($h['bank'])->toBe('0.150')                   // actual fee
        ->and($h['bank_estimated'])->toBe('0.090')
        ->and($h['gross'])->toBe('3.000')                  // Σ preserved
        ->and($h['num_sales'])->toBe(1)
        ->and($h['num_settled'])->toBe(1);

    expect($res->json('data.by_branch.0.merchant_net'))->toBe('2.790');
    expect($res->json('data.by_branch.0.num_settled'))->toBe(1);
});

it('rolls the commission up per month with a finalized vs pending split', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;

    // June: one sale, claimed into a PAID payout → finalized.
    $payoutId = (int) DB::table('pos_payouts')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $cid,
        'period_from' => '2026-06-01 00:00:00',
        'period_to' => '2026-06-30 23:59:59',
        'status' => 'paid',
        'paid_at' => '2026-06-25 12:00:00',
        'created_at' => '2026-06-20 10:00:00',
        'updated_at' => '2026-06-20 10:00:00',
    ]);
    seedSale($cid, 1, 10, '0.100', '0.090', '2.810', '2026-06-10 10:00:00'); // gross 3.000
    DB::table('pos_sale_commissions')->where('order_id', 1)->where('party_type', 'merchant')->update(['payout_id' => $payoutId]);

    // July: one sale, no payout → pending.
    seedSale($cid, 2, 10, '0.040', '0.000', '1.960', '2026-07-05 10:00:00'); // gross 2.000

    $rows = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-07-31')
        ->assertOk()->json('data.by_month');

    expect($rows)->toHaveCount(2);

    expect($rows[0]['month'])->toBe('2026-06');       // chronological
    expect($rows[0]['gross'])->toBe('3.000');
    expect($rows[0]['admin_commission'])->toBe('0.100');
    expect($rows[0]['bank_commission'])->toBe('0.090');
    expect($rows[0]['commission_total'])->toBe('0.190');
    expect($rows[0]['merchant_net'])->toBe('2.810');
    expect($rows[0]['finalized_net'])->toBe('2.810'); // payout paid
    expect($rows[0]['pending_net'])->toBe('0.000');
    expect($rows[0]['num_sales'])->toBe(1);

    expect($rows[1]['month'])->toBe('2026-07');
    expect($rows[1]['merchant_net'])->toBe('1.960');
    expect($rows[1]['finalized_net'])->toBe('0.000');
    expect($rows[1]['pending_net'])->toBe('1.960'); // not paid out
});

it('breaks the payout down per branch', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    seedSale($cid, 1, 10, '0.060', '0.090', '2.850');
    seedSale($cid, 2, 20, '0.040', '0.000', '1.960');

    $rows = $this->getJson('/api/reports/payouts?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data.by_branch');

    expect($rows)->toHaveCount(2);
    // Sorted by merchant_net desc → branch 10 (2.850) first.
    expect($rows[0]['branch_id'])->toBe(10);
    expect($rows[0]['merchant_net'])->toBe('2.850');
    expect($rows[1]['branch_id'])->toBe(20);
    expect($rows[1]['merchant_net'])->toBe('1.960');
});

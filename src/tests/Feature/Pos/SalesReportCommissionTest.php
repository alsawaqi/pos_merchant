<?php

declare(strict_types=1);

/**
 * Slice B — commission folded into the Sales Report (GET /api/reports/sales).
 *
 * net_profit nets the admin/bank/other commission (settled-aware) alongside
 * expenses; the headline also exposes the commission breakdown + the merchant's
 * take split into FINALIZED (payout paid, or no-commission cash) vs PENDING
 * (still held until a payout is paid).
 */

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Unique helper names (Pest loads every test file).
function srcRow(int $companyId, int $orderId, int $branchId, string $party, string $amount, int $sort, string $gross, ?string $settled = null, bool $isSettled = false, ?int $payoutId = null): void
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
        'settled_amount' => $settled,
        'is_settled' => $isSettled,
        'payout_id' => $payoutId,
        'sort_order' => $sort,
        'occurred_at' => '2026-06-15 10:00:00',
        'created_at' => '2026-06-15 10:00:00',
        'updated_at' => '2026-06-15 10:00:00',
    ]);
}

function srcPayout(int $companyId, string $status, ?string $paidAt): int
{
    return (int) DB::table('pos_payouts')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'period_from' => '2026-06-01 00:00:00',
        'period_to' => '2026-06-30 23:59:59',
        'status' => $status,
        'paid_at' => $paidAt,
        'created_at' => '2026-06-16 10:00:00',
        'updated_at' => '2026-06-16 10:00:00',
    ]);
}

function srcOrder(array $ctx, string $amount): Order
{
    // subtotal == grand_total (no tax) so the commission gross matches net_sales.
    return Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => $amount,
        'discount_total' => '0.000',
        'tax_total' => '0.000',
        'grand_total' => $amount,
        'opened_at' => '2026-06-15 10:00:00',
    ]);
}

function srcHeadline(object $test): array
{
    return $test->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.headline');
}

it('folds commission into net_profit + exposes the breakdown', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    $bid = $ctx['branch']->id;

    $a = srcOrder($ctx, '3.000');
    srcRow($cid, $a->id, $bid, 'platform', '0.100', 0, '3.000');
    srcRow($cid, $a->id, $bid, 'bank', '0.090', 1, '3.000');
    srcRow($cid, $a->id, $bid, 'merchant', '2.810', 2, '3.000');

    $b = srcOrder($ctx, '2.000');
    srcRow($cid, $b->id, $bid, 'platform', '0.040', 0, '2.000');
    srcRow($cid, $b->id, $bid, 'merchant', '1.960', 1, '2.000');

    $h = srcHeadline($this);

    expect($h['net_sales'])->toBe('5.000');
    expect($h['admin_commission'])->toBe('0.140');
    expect($h['bank_commission'])->toBe('0.090');
    expect($h['commission_total'])->toBe('0.230');
    expect($h['merchant_net'])->toBe('4.770');       // 2.810 + 1.960
    expect($h['net_profit'])->toBe('4.770');          // 5.000 − 0.230 (no expenses)
});

it('uses the settled fee for commission + net once reconciled', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    $bid = $ctx['branch']->id;

    $a = srcOrder($ctx, '3.000');
    srcRow($cid, $a->id, $bid, 'platform', '0.100', 0, '3.000', '0.100', true);
    srcRow($cid, $a->id, $bid, 'bank', '0.090', 1, '3.000', '0.150', true);   // actual fee
    srcRow($cid, $a->id, $bid, 'merchant', '2.810', 2, '3.000', '2.750', true); // absorbs variance

    $h = srcHeadline($this);

    expect($h['bank_commission'])->toBe('0.150');
    expect($h['commission_total'])->toBe('0.250');   // 0.100 + 0.150
    expect($h['merchant_net'])->toBe('2.750');
    expect($h['net_profit'])->toBe('2.750');          // 3.000 − 0.250
});

it('splits the merchant take into finalized (paid out) vs pending', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    $bid = $ctx['branch']->id;

    $payoutId = srcPayout($cid, 'paid', '2026-06-20 12:00:00');

    // Paid out → finalized.
    $a = srcOrder($ctx, '3.000');
    srcRow($cid, $a->id, $bid, 'platform', '0.100', 0, '3.000', '0.100', true);
    srcRow($cid, $a->id, $bid, 'bank', '0.090', 1, '3.000', '0.090', true);
    srcRow($cid, $a->id, $bid, 'merchant', '2.810', 2, '3.000', '2.810', true, $payoutId);

    // Not in any payout → pending.
    $b = srcOrder($ctx, '2.000');
    srcRow($cid, $b->id, $bid, 'platform', '0.040', 0, '2.000');
    srcRow($cid, $b->id, $bid, 'merchant', '1.960', 1, '2.000');

    $h = srcHeadline($this);

    expect($h['merchant_net'])->toBe('4.770');
    expect($h['finalized_net'])->toBe('2.810');
    expect($h['pending_net'])->toBe('1.960');
});

it('counts a no-commission cash sale as finalized income', function (): void {
    $ctx = makeMerchantActor();
    $cid = $ctx['company']->id;
    $bid = $ctx['branch']->id;

    // Commissioned + pending.
    $a = srcOrder($ctx, '3.000');
    srcRow($cid, $a->id, $bid, 'platform', '0.190', 0, '3.000');
    srcRow($cid, $a->id, $bid, 'merchant', '2.810', 1, '3.000');

    // No commission profile → merchant keeps 100%, cash already in hand.
    srcOrder($ctx, '5.000');

    $h = srcHeadline($this);

    expect($h['merchant_net'])->toBe('7.810');       // 2.810 + 5.000
    expect($h['finalized_net'])->toBe('5.000');       // the no-commission cash
    expect($h['pending_net'])->toBe('2.810');
    expect($h['commission_total'])->toBe('0.190');
});

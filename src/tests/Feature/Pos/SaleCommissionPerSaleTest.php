<?php

declare(strict_types=1);

/**
 * Per-sale commission + reconciliation/payout STATUS on the merchant's
 * orders list (GET /api/orders) and order detail (GET /api/orders/{uuid}).
 *
 * A sale is settled-aware (the bank's ACTUAL fee where reconciled, else the
 * estimate) and FINALIZED only once its payout is marked PAID:
 *   none → pending → reconciled → in_payout → paid.
 */

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Unique helper names (Pest loads every test file — names must not collide
// with PayoutBreakdownReportTest's commissionRow/seedSale).
function slcRow(int $companyId, int $orderId, int $branchId, string $party, string $amount, int $sort, string $gross, ?string $settled = null, bool $isSettled = false, ?int $payoutId = null): void
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

function slcPayout(int $companyId, string $status, ?string $paidAt): int
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

function slcOrder(array $ctx, string $grandTotal): Order
{
    return Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => $grandTotal,
        'opened_at' => Carbon::parse('2026-06-15 10:00:00'),
    ]);
}

function slcFirstRow(object $test): array
{
    return $test->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows.0');
}

it('shows each sale commission split + a pending status before reconciliation', function (): void {
    $ctx = makeMerchantActor();
    $o = slcOrder($ctx, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'platform', '0.100', 0, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'bank', '0.090', 1, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'merchant', '2.810', 2, '3.000');

    $row = slcFirstRow($this);

    expect($row['admin_commission'])->toBe('0.100');
    expect($row['bank_commission'])->toBe('0.090');
    expect($row['total_commission'])->toBe('0.190');
    expect($row['merchant_net'])->toBe('2.810');
    expect($row['commission_status'])->toBe('pending');
    expect($row['is_finalized'])->toBeFalse();
    expect($row['payout_date'])->toBeNull();
});

it('uses the SETTLED net + reconciled status once a card sale is reconciled', function (): void {
    $ctx = makeMerchantActor();
    $o = slcOrder($ctx, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'platform', '0.100', 0, '3.000', '0.100', true);
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'bank', '0.090', 1, '3.000', '0.150', true); // actual fee
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'merchant', '2.810', 2, '3.000', '2.750', true); // absorbs variance

    $row = slcFirstRow($this);

    expect($row['bank_commission'])->toBe('0.150'); // actual, not estimate
    expect($row['merchant_net'])->toBe('2.750');    // settled
    expect($row['commission_status'])->toBe('reconciled');
    expect($row['is_finalized'])->toBeFalse();
});

it('marks the sale paid + carries the payout date once its payout is paid', function (): void {
    $ctx = makeMerchantActor();
    $payoutId = slcPayout($ctx['company']->id, 'paid', '2026-06-20 12:00:00');
    $o = slcOrder($ctx, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'platform', '0.100', 0, '3.000', '0.100', true);
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'bank', '0.150', 1, '3.000', '0.150', true);
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'merchant', '2.750', 2, '3.000', '2.750', true, $payoutId);

    $row = slcFirstRow($this);

    expect($row['commission_status'])->toBe('paid');
    expect($row['is_finalized'])->toBeTrue();
    expect($row['payout_date'])->toBe('2026-06-20T12:00:00');
    expect($row['merchant_net'])->toBe('2.750');
});

it('flags a sale claimed into a still-pending payout as in_payout', function (): void {
    $ctx = makeMerchantActor();
    $payoutId = slcPayout($ctx['company']->id, 'pending', null);
    $o = slcOrder($ctx, '2.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'platform', '0.040', 0, '2.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'merchant', '1.960', 1, '2.000', null, false, $payoutId);

    $row = slcFirstRow($this);

    expect($row['commission_status'])->toBe('in_payout');
    expect($row['is_finalized'])->toBeFalse();
});

it('treats a sale with no commission profile as the merchant keeping 100%', function (): void {
    $ctx = makeMerchantActor();
    slcOrder($ctx, '5.000'); // no commission rows seeded

    $row = slcFirstRow($this);

    expect($row['total_commission'])->toBe('0.000');
    expect($row['merchant_net'])->toBe('5.000');
    expect($row['commission_status'])->toBe('none');
});

it('values a fully gifted no-commission sale at the collected amount (zero)', function (): void {
    $ctx = makeMerchantActor();
    $o = slcOrder($ctx, '5.000'); // no commission rows (fully gifted → collected 0)
    DB::table('pos_payments')->insert([
        'uuid' => (string) Str::uuid(),
        'order_id' => $o->id,
        'method' => 'gift',
        'amount' => '5.000',
        'status' => 'success',
        'created_at' => '2026-06-15 10:00:00',
        'updated_at' => '2026-06-15 10:00:00',
    ]);

    $row = slcFirstRow($this);
    expect($row['commission_status'])->toBe('none');
    expect($row['merchant_net'])->toBe('0.000'); // gifted, never collected

    $c = $this->getJson("/api/orders/{$o->uuid}")->assertOk()->json('data.commission');
    expect($c['merchant_net'])->toBe('0.000');
});

it('includes the commission block in the order detail', function (): void {
    $ctx = makeMerchantActor();
    $o = slcOrder($ctx, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'platform', '0.100', 0, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'bank', '0.090', 1, '3.000');
    slcRow($ctx['company']->id, $o->id, $ctx['branch']->id, 'merchant', '2.810', 2, '3.000');

    $c = $this->getJson("/api/orders/{$o->uuid}")->assertOk()->json('data.commission');

    expect($c['admin_commission'])->toBe('0.100');
    expect($c['bank_commission'])->toBe('0.090');
    expect($c['merchant_net'])->toBe('2.810');
    expect($c['commission_status'])->toBe('pending');
});

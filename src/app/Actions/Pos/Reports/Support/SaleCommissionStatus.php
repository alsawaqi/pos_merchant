<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports\Support;

use App\Enums\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-order commission breakdown + reconciliation/payout STATUS for the
 * merchant, derived from the append-only pos_sale_commissions ledger + the
 * stateful pos_payouts. Settled-aware: the bank's ACTUAL fee where a card sale
 * has been reconciled, the estimate otherwise (COALESCE(settled, estimate)).
 * One batched read per set of order ids — no N+1. Shared by the orders list +
 * order detail (and the same ledger the payout report aggregates).
 *
 * A sale's take-home money is FINAL once it's either realised in hand or paid out:
 *   none       — no commission profile applied; the merchant keeps 100% (no
 *                platform/bank cut, nothing to settle or pay out)
 *   direct     — a pure CASH/BANK_POS sale: the merchant COLLECTED the money
 *                directly, so their net is realised on the spot (not awaiting any
 *                payout). The platform's cut is billed back via a commission
 *                invoice — Phase B. This is the money the merchant already holds.
 *   pending    — a card sale, not reconciled, not in any payout (estimate)
 *   reconciled — a card sale settled against the bank, not yet paid out
 *   in_payout  — claimed into a payout that is still PENDING
 *   paid       — in a payout the admin marked PAID (final; carries payout_date)
 *
 * Driver-portable (sqlite tests + Postgres prod): no MAX(boolean) — is_settled
 * is folded via CASE; the settled-aware SUM mirrors PayoutBreakdownReportAction.
 */
final class SaleCommissionStatus
{
    public const NONE = 'none';
    public const DIRECT = 'direct';
    public const PENDING = 'pending';
    public const RECONCILED = 'reconciled';
    public const IN_PAYOUT = 'in_payout';
    public const PAID = 'paid';

    /** Non-failed tender methods that mean the merchant collected the money directly. */
    private const MERCHANT_HELD_METHODS = ['cash', 'bank_pos'];

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array<string, mixed>>  order_id => breakdown (only
     *         orders that HAVE commission rows; callers default the rest to NONE)
     */
    public static function forOrders(int $companyId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        // Settled-aware per (order, party) sums + per-order is_settled + the
        // merchant row's payout_id (CreatePayoutAction stamps the claim there;
        // platform/bank/other rows never carry one).
        $rows = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->whereIn('order_id', $orderIds)
            ->selectRaw('
                order_id,
                party_type,
                COALESCE(SUM(COALESCE(settled_amount, commission_amount)), 0) AS amount,
                MAX(CASE WHEN is_settled THEN 1 ELSE 0 END) AS settled,
                MAX(payout_id) AS payout_id
            ')
            ->groupBy('order_id', 'party_type')
            ->get();

        $payoutIds = $rows->pluck('payout_id')->filter()->unique()->values()->all();
        $payouts = $payoutIds === [] ? collect() : DB::table('pos_payouts')
            ->whereIn('id', $payoutIds)
            ->get(['id', 'status', 'paid_at'])
            ->keyBy('id');

        // Pure cash/bank_pos orders — money the merchant collected directly, so
        // their net is realised on the spot (never swept into a payout; billed
        // via a commission invoice instead). A flip to $direct[$oid] = true.
        $direct = array_fill_keys(self::directOrderIds($companyId, $orderIds), true);

        /** @var array<int, array<string, mixed>> $agg */
        $agg = [];
        foreach ($rows as $r) {
            $oid = (int) $r->order_id;
            $agg[$oid] ??= [
                'platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0,
                'is_settled' => false, 'payout_id' => null,
            ];
            $party = (string) $r->party_type;
            if (array_key_exists($party, $agg[$oid])) {
                $agg[$oid][$party] = (float) $r->amount;
            }
            if ($party === 'merchant' && $r->payout_id !== null) {
                $agg[$oid]['payout_id'] = (int) $r->payout_id;
            }
            if ((int) $r->settled === 1) {
                $agg[$oid]['is_settled'] = true;
            }
        }

        $out = [];
        foreach ($agg as $oid => $a) {
            $totalCommission = $a['platform'] + $a['bank'] + $a['other'];
            $payout = $a['payout_id'] !== null ? $payouts->get($a['payout_id']) : null;
            $payoutStatus = $payout?->status; // pending | paid | cancelled | null
            $paidAt = $payoutStatus === 'paid' ? $payout?->paid_at : null;

            // A cancelled payout normally releases payout_id, but guard anyway:
            // only a LIVE (pending/paid) payout counts as a claim. A pure cash/
            // bank_pos sale is realised in hand (DIRECT) — its money never rides a
            // payout, so it must NOT show as "pending" forever.
            $status = self::PENDING;
            if ($payoutStatus === 'paid') {
                $status = self::PAID;
            } elseif ($payoutStatus === 'pending') {
                $status = self::IN_PAYOUT;
            } elseif (isset($direct[$oid])) {
                $status = self::DIRECT;
            } elseif ($a['is_settled']) {
                $status = self::RECONCILED;
            }

            $out[$oid] = [
                'admin_commission' => number_format($a['platform'], 3, '.', ''),
                'bank_commission' => number_format($a['bank'], 3, '.', ''),
                'other_commission' => number_format($a['other'], 3, '.', ''),
                'total_commission' => number_format($totalCommission, 3, '.', ''),
                'merchant_net' => number_format($a['merchant'], 3, '.', ''),
                'is_settled' => $a['is_settled'],
                'commission_status' => $status,
                // Realised income: paid out to the merchant, OR cash they collected
                // directly. Either way the net is final (not awaiting a payout).
                'is_finalized' => $status === self::PAID || $status === self::DIRECT,
                'payout_date' => $paidAt !== null ? Carbon::parse($paidAt)->format('Y-m-d\TH:i:s') : null,
            ];
        }

        return $out;
    }

    /**
     * The subset of $orderIds that are PURE cash/bank_pos sales — a cash/bank_pos
     * tender AND no card tender (money the merchant collected directly). Batched,
     * anchored on company_id through pos_orders (mirrors giftTotals()); returns a
     * plain list of order ids.
     *
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private static function directOrderIds(int $companyId, array $orderIds): array
    {
        $held = DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->whereIn('pos_payments.order_id', $orderIds)
            ->whereIn('pos_payments.method', self::MERCHANT_HELD_METHODS)
            ->where('pos_payments.status', '<>', 'failed')
            ->distinct()
            ->pluck('pos_payments.order_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        if ($held === []) {
            return [];
        }

        $card = DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->whereIn('pos_payments.order_id', $held)
            ->where('pos_payments.method', 'card')
            ->where('pos_payments.status', '<>', 'failed')
            ->distinct()
            ->pluck('pos_payments.order_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return array_values(array_diff($held, $card));
    }

    /**
     * Default breakdown for an order with NO commission rows — either a merchant
     * with no commission profile, or a FULLY GIFTED order (pos_api records no
     * rows when collected == 0). The merchant keeps what was COLLECTED, so the
     * net is grand_total minus the never-collected gifted portion (mirrors
     * pos_api + the Sales-report aggregate). $gifted defaults to 0 (a normal
     * no-profile cash sale keeps the full gross).
     *
     * @return array<string, mixed>
     */
    public static function none(string $grandTotal, float $gifted = 0.0): array
    {
        $net = max(0.0, (float) $grandTotal - $gifted);

        return [
            'admin_commission' => '0.000',
            'bank_commission' => '0.000',
            'other_commission' => '0.000',
            'total_commission' => '0.000',
            'merchant_net' => number_format($net, 3, '.', ''),
            'is_settled' => false,
            'commission_status' => self::NONE,
            'is_finalized' => false,
            'payout_date' => null,
        ];
    }

    /**
     * Gifted (never-collected) OMR per order — Σ successful 'gift' tenders. Used
     * to value no-commission orders at the collected amount. Batched; returns
     * only orders that have a gift tender (callers default the rest to 0).
     *
     * @param  list<int>  $orderIds
     * @return array<int, float>  order_id => gifted OMR
     */
    public static function giftTotals(int $companyId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        // pos_payments has no company_id (it is tenant-owned transitively via
        // order_id). Join through pos_orders and anchor on company_id so the
        // totals can never include another tenant's gift tenders even if a
        // caller ever passes un-scoped order ids — mirrors forOrders() above.
        return DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->whereIn('pos_payments.order_id', $orderIds)
            ->where('pos_payments.method', PaymentMethod::Gift->value)
            ->where('pos_payments.status', 'success')
            ->selectRaw('pos_payments.order_id AS order_id, COALESCE(SUM(pos_payments.amount), 0) AS gifted')
            ->groupBy('pos_payments.order_id')
            ->pluck('gifted', 'order_id')
            ->map(static fn ($v): float => (float) $v)
            ->all();
    }
}

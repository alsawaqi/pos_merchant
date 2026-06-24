<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports\Support;

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
 * Per the agreed rule, a sale is FINALIZED only once its payout is PAID:
 *   none       — no commission profile applied; the merchant keeps 100% (no
 *                platform/bank cut, nothing to settle or pay out)
 *   pending    — has commission, not reconciled, not in any payout (estimate)
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
    public const PENDING = 'pending';
    public const RECONCILED = 'reconciled';
    public const IN_PAYOUT = 'in_payout';
    public const PAID = 'paid';

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
            // only a LIVE (pending/paid) payout counts as a claim.
            $status = self::PENDING;
            if ($payoutStatus === 'paid') {
                $status = self::PAID;
            } elseif ($payoutStatus === 'pending') {
                $status = self::IN_PAYOUT;
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
                'is_finalized' => $status === self::PAID,
                'payout_date' => $paidAt !== null ? Carbon::parse($paidAt)->format('Y-m-d\TH:i:s') : null,
            ];
        }

        return $out;
    }

    /**
     * Default breakdown for an order with NO commission rows (no profile) — the
     * merchant keeps the full gross; nothing is owed, settled, or paid out.
     *
     * @return array<string, mixed>
     */
    public static function none(string $grandTotal): array
    {
        return [
            'admin_commission' => '0.000',
            'bank_commission' => '0.000',
            'other_commission' => '0.000',
            'total_commission' => '0.000',
            'merchant_net' => $grandTotal,
            'is_settled' => false,
            'commission_status' => self::NONE,
            'is_finalized' => false,
            'payout_date' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v2 #17 (Phase A) — the merchant's PAYOUT / commission breakdown.
 *
 * Reads the append-only pos_sale_commissions ledger (written per paid sale by
 * pos_api's RecordSaleCommissionAction — one row per party: platform / bank /
 * other, plus the merchant residual; voided sales are removed). For a date range
 * it shows, in OMR, what the merchant earned and what each party took:
 *
 *   gross         = Σ commission_amount over ALL parties (== Σ grand_total)
 *   platform/bank/other = each party's take in the window
 *   merchant_net  = the merchant's residual — what the platform settles to them
 *
 * Direction-agnostic visibility: it reports the recorded splits, independent of
 * who physically holds the cash. The stateful payout (pos_payouts) is Phase B.
 *
 * Filtered on occurred_at (the sale's close time) + the company + optional
 * branch scope. Money is emitted as decimal-3 strings, like every report.
 */
final readonly class PayoutBreakdownReportAction
{
    private const PARTIES = ['platform', 'bank', 'other', 'merchant'];

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();

        $base = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->whereBetween('occurred_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $base->whereIn('branch_id', $branchScope);
        }

        // ---- Totals per party ----
        // `total` is settled-aware (the bank's ACTUAL fee where a card sale has
        // been reconciled, the estimate otherwise); `est_total` is the pure
        // estimate, so the merchant sees both their live estimate and the final
        // figure as reconciliation lands.
        $byParty = (clone $base)
            ->selectRaw('party_type, COALESCE(SUM(COALESCE(settled_amount, commission_amount)), 0) AS total, COALESCE(SUM(commission_amount), 0) AS est_total')
            ->groupBy('party_type')
            ->get()
            ->keyBy('party_type');

        $amount = static fn (string $party): float => (float) (optional($byParty->get($party))->total ?? 0);
        $estimate = static fn (string $party): float => (float) (optional($byParty->get($party))->est_total ?? 0);
        $platform = $amount('platform');
        $bank = $amount('bank');
        $other = $amount('other');
        $merchantNet = $amount('merchant');
        $gross = $platform + $bank + $other + $merchantNet;
        $bankEstimated = $estimate('bank');
        $merchantNetEstimated = $estimate('merchant');

        $numSales = (int) (clone $base)->distinct()->count('order_id');
        // Sales whose commission has been reconciled against the bank's fee.
        $numSettled = (int) (clone $base)->where('is_settled', true)->distinct()->count('order_id');

        // ---- Per-party array (for the donut) ----
        $parties = array_map(static fn (string $p): array => [
            'party_type' => $p,
            'total' => number_format($p === 'merchant' ? $merchantNet : ($p === 'platform' ? $platform : ($p === 'bank' ? $bank : $other)), 3, '.', ''),
        ], self::PARTIES);

        // ---- By branch ---- (settled-aware, like the headline)
        $byBranchRows = (clone $base)
            ->selectRaw('branch_id, party_type, COALESCE(SUM(COALESCE(settled_amount, commission_amount)), 0) AS total')
            ->groupBy('branch_id', 'party_type')
            ->get();

        /** @var array<int, array<string, float>> $branchAgg */
        $branchAgg = [];
        $branchOrders = (clone $base)
            ->selectRaw('branch_id, COUNT(DISTINCT order_id) AS sales')
            ->groupBy('branch_id')
            ->pluck('sales', 'branch_id');
        $branchSettled = (clone $base)
            ->where('is_settled', true)
            ->selectRaw('branch_id, COUNT(DISTINCT order_id) AS sales')
            ->groupBy('branch_id')
            ->pluck('sales', 'branch_id');
        foreach ($byBranchRows as $r) {
            $bid = (int) $r->branch_id;
            $branchAgg[$bid] ??= ['platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0];
            if (array_key_exists((string) $r->party_type, $branchAgg[$bid])) {
                $branchAgg[$bid][(string) $r->party_type] = (float) $r->total;
            }
        }
        $byBranch = [];
        foreach ($branchAgg as $bid => $a) {
            $bGross = $a['platform'] + $a['bank'] + $a['other'] + $a['merchant'];
            $byBranch[] = [
                'branch_id' => $bid,
                'gross' => number_format($bGross, 3, '.', ''),
                'platform' => number_format($a['platform'], 3, '.', ''),
                'bank' => number_format($a['bank'], 3, '.', ''),
                'other' => number_format($a['other'], 3, '.', ''),
                'merchant_net' => number_format($a['merchant'], 3, '.', ''),
                'num_sales' => (int) ($branchOrders[$bid] ?? 0),
                'num_settled' => (int) ($branchSettled[$bid] ?? 0),
            ];
        }
        usort($byBranch, static fn (array $x, array $y): int => (float) $y['merchant_net'] <=> (float) $x['merchant_net']);

        $byMonth = $this->byMonth($companyId, $filter, $branchScope);

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'gross' => number_format($gross, 3, '.', ''),
                'platform' => number_format($platform, 3, '.', ''),
                'bank' => number_format($bank, 3, '.', ''),
                'other' => number_format($other, 3, '.', ''),
                'merchant_net' => number_format($merchantNet, 3, '.', ''),
                // Pure estimate, so the merchant can compare against the
                // settled-aware figures above as reconciliation lands.
                'bank_estimated' => number_format($bankEstimated, 3, '.', ''),
                'merchant_net_estimated' => number_format($merchantNetEstimated, 3, '.', ''),
                'num_sales' => $numSales,
                'num_settled' => $numSettled,
            ],
            'parties' => $parties,
            'by_branch' => $byBranch,
            'by_month' => $byMonth,
        ];
    }

    /**
     * Monthly commission roll-up over the window (chronological). Per month:
     * gross + the admin/bank/other commission + commission_total, plus the
     * merchant's net take split into FINALIZED (its payout is PAID) vs PENDING
     * (still held until paid out). Settled-aware, mirroring the headline; the
     * payout-paid bucket is the same rule as the per-sale status + the Sales
     * report. Driver-aware month key (sqlite strftime / Postgres to_char).
     *
     * @param  list<int>|null  $branchScope
     * @return list<array{month: string, num_sales: int, gross: string, admin_commission: string, bank_commission: string, other_commission: string, commission_total: string, merchant_net: string, finalized_net: string, pending_net: string}>
     */
    private function byMonth(int $companyId, ReportFilter $filter, ?array $branchScope): array
    {
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', pos_sale_commissions.occurred_at)"
            : "to_char(pos_sale_commissions.occurred_at, 'YYYY-MM')";

        // Qualified scope — once pos_payouts is joined, company_id is ambiguous
        // (pos_payouts has one too), so every predicate names its table.
        $scoped = static function ($q) use ($companyId, $filter, $branchScope) {
            $q->where('pos_sale_commissions.company_id', $companyId)
                ->whereBetween('pos_sale_commissions.occurred_at', [$filter->dateFrom, $filter->dateTo]);
            if ($branchScope !== null) {
                $q->whereIn('pos_sale_commissions.branch_id', $branchScope);
            }

            return $q;
        };

        $rows = $scoped(
            DB::table('pos_sale_commissions')
                ->leftJoin('pos_payouts', 'pos_payouts.id', '=', 'pos_sale_commissions.payout_id'),
        )
            ->selectRaw("$monthExpr AS month, pos_sale_commissions.party_type AS party,
                COALESCE(SUM(COALESCE(pos_sale_commissions.settled_amount, pos_sale_commissions.commission_amount)), 0) AS amount,
                COALESCE(SUM(CASE WHEN pos_payouts.status = 'paid' THEN COALESCE(pos_sale_commissions.settled_amount, pos_sale_commissions.commission_amount) ELSE 0 END), 0) AS paid_amount")
            ->groupByRaw("$monthExpr, pos_sale_commissions.party_type")
            ->get();

        $salesByMonth = $scoped(DB::table('pos_sale_commissions'))
            ->selectRaw("$monthExpr AS month, COUNT(DISTINCT pos_sale_commissions.order_id) AS cnt")
            ->groupByRaw($monthExpr)
            ->pluck('cnt', 'month');

        /** @var array<string, array<string, float>> $agg */
        $agg = [];
        foreach ($rows as $r) {
            $m = (string) $r->month;
            $agg[$m] ??= ['platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0, 'merchant_paid' => 0.0];
            $party = (string) $r->party;
            if (array_key_exists($party, $agg[$m])) {
                $agg[$m][$party] = (float) $r->amount;
            }
            if ($party === 'merchant') {
                $agg[$m]['merchant_paid'] = (float) $r->paid_amount;
            }
        }

        ksort($agg); // YYYY-MM strings sort chronologically

        $out = [];
        foreach ($agg as $m => $a) {
            $commission = $a['platform'] + $a['bank'] + $a['other'];
            $merch = $a['merchant'];
            $out[] = [
                'month' => $m,
                'num_sales' => (int) ($salesByMonth[$m] ?? 0),
                'gross' => self::money($commission + $merch),
                'admin_commission' => self::money($a['platform']),
                'bank_commission' => self::money($a['bank']),
                'other_commission' => self::money($a['other']),
                'commission_total' => self::money($commission),
                'merchant_net' => self::money($merch),
                'finalized_net' => self::money($a['merchant_paid']),
                'pending_net' => self::money($merch - $a['merchant_paid']),
            ];
        }

        return $out;
    }

    private static function money(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

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
        $byParty = (clone $base)
            ->selectRaw('party_type, COALESCE(SUM(commission_amount), 0) AS total')
            ->groupBy('party_type')
            ->pluck('total', 'party_type');

        $amount = static fn (string $party): float => (float) ($byParty[$party] ?? 0);
        $platform = $amount('platform');
        $bank = $amount('bank');
        $other = $amount('other');
        $merchantNet = $amount('merchant');
        $gross = $platform + $bank + $other + $merchantNet;

        $numSales = (int) (clone $base)->distinct()->count('order_id');

        // ---- Per-party array (for the donut) ----
        $parties = array_map(static fn (string $p): array => [
            'party_type' => $p,
            'total' => number_format($p === 'merchant' ? $merchantNet : ($p === 'platform' ? $platform : ($p === 'bank' ? $bank : $other)), 3, '.', ''),
        ], self::PARTIES);

        // ---- By branch ----
        $byBranchRows = (clone $base)
            ->selectRaw('branch_id, party_type, COALESCE(SUM(commission_amount), 0) AS total')
            ->groupBy('branch_id', 'party_type')
            ->get();

        /** @var array<int, array<string, float>> $branchAgg */
        $branchAgg = [];
        $branchOrders = (clone $base)
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
            ];
        }
        usort($byBranch, static fn (array $x, array $y): int => (float) $y['merchant_net'] <=> (float) $x['merchant_net']);

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
                'num_sales' => $numSales,
            ],
            'parties' => $parties,
            'by_branch' => $byBranch,
        ];
    }
}

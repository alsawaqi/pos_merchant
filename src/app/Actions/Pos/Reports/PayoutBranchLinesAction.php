<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * TWIN of pos_admin App\Actions\Admin\Payouts\PayoutBranchLinesAction — keep in
 * sync. The per-branch breakdown of a payout (the statement detail the merchant
 * receives), derived from the payout's CLAIMED sales: the claim stamps payout_id
 * on the merchant rows, so those order ids are the payout's sales. Every party
 * row of those orders is summed per branch, settled-aware (the bank's ACTUAL fee
 * where reconciled, the estimate otherwise). The caller MUST tenant-check the
 * payout before calling this.
 */
final class PayoutBranchLinesAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Payout $payout): array
    {
        $orderIds = DB::table('pos_sale_commissions')
            ->where('payout_id', $payout->id)
            ->where('party_type', 'merchant')
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return [];
        }

        $partyRows = DB::table('pos_sale_commissions as sc')
            ->join('pos_branches', 'pos_branches.id', '=', 'sc.branch_id')
            ->whereIn('sc.order_id', $orderIds)
            ->selectRaw('
                sc.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                sc.party_type AS party_type,
                COALESCE(SUM(COALESCE(sc.settled_amount, sc.commission_amount)), 0) AS total
            ')
            ->groupBy('sc.branch_id', 'pos_branches.name', 'sc.party_type')
            ->get();

        $salesByBranch = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('branch_id, COUNT(DISTINCT order_id) AS sales')
            ->groupBy('branch_id')
            ->pluck('sales', 'branch_id');

        /** @var array<int, array<string, float>> $agg */
        $agg = [];
        /** @var array<int, string> $names */
        $names = [];
        foreach ($partyRows as $r) {
            $bid = (int) $r->branch_id;
            $agg[$bid] ??= ['platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0];
            $names[$bid] ??= (string) $r->branch_name;
            $party = (string) $r->party_type;
            if (array_key_exists($party, $agg[$bid])) {
                $agg[$bid][$party] = (float) $r->total;
            }
        }

        $lines = [];
        foreach ($agg as $bid => $a) {
            $gross = $a['platform'] + $a['bank'] + $a['other'] + $a['merchant'];
            $lines[] = [
                'branch_id' => $bid,
                'branch_name' => $names[$bid] ?? '',
                'gross' => number_format($gross, 3, '.', ''),
                'platform' => number_format($a['platform'], 3, '.', ''),
                'bank' => number_format($a['bank'], 3, '.', ''),
                'other' => number_format($a['other'], 3, '.', ''),
                'merchant_net' => number_format($a['merchant'], 3, '.', ''),
                'num_sales' => (int) ($salesByBranch[$bid] ?? 0),
            ];
        }
        usort($lines, static fn (array $x, array $y): int => (float) $y['merchant_net'] <=> (float) $x['merchant_net']);

        return $lines;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v2 #18 — Round-Up Donation Report (blueprint §5.11.9).
 *
 * The charity round-up is LIVE: a card payment's rounded-off slice is recorded
 * in pos_roundup_donations (written by pos_api's donation.record handler; status
 * success | pending | fail, and 'void' once its sale is voided). This report
 * aggregates that table for the merchant's company over the window: total raised
 * (successful donations), how many, the pending/failed counts, plus per-branch
 * and per-status breakdowns.
 *
 * (Was a Phase-7b zero stub — the donation infra has since shipped, so this now
 * reads the real table. No per-charity breakdown exists: round-ups are POS-owned
 * and forwarded to the single platform charity, not a per-charity directory.)
 *
 * Money is decimal-3 strings; filtered on occurred_at, tenant-scoped.
 */
final readonly class RoundUpDonationReportAction
{
    /** Donation statuses written by pos_api's donation.record / void path. */
    private const STATUSES = ['success', 'pending', 'fail', 'void'];

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

        $base = DB::table('pos_roundup_donations')
            ->where('company_id', $companyId)
            ->whereBetween('occurred_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $base->whereIn('branch_id', $branchScope);
        }

        // Per-status totals.
        $byStatusRows = (clone $base)
            ->selectRaw('status, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statusTotal = static fn (string $s): float => (float) ($byStatusRows[$s]->total ?? 0);
        $statusCount = static fn (string $s): int => (int) ($byStatusRows[$s]->cnt ?? 0);

        $byStatus = [];
        foreach (self::STATUSES as $s) {
            if (! $byStatusRows->has($s)) {
                continue;
            }
            $byStatus[] = [
                'status' => $s,
                'total' => number_format($statusTotal($s), 3, '.', ''),
                'count' => $statusCount($s),
            ];
        }

        // Per-branch raised (successful donations only — that's money actually
        // collected for charity).
        $byBranch = (clone $base)
            ->where('status', 'success')
            ->selectRaw('branch_id, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('branch_id')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'total_raised' => number_format((float) $r->total, 3, '.', ''),
                'donation_count' => (int) $r->cnt,
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'total_raised' => number_format($statusTotal('success'), 3, '.', ''),
                'donation_count' => $statusCount('success'),
                'pending_count' => $statusCount('pending'),
                'failed_count' => $statusCount('fail'),
            ],
            'by_branch' => $byBranch,
            'by_status' => $byStatus,
        ];
    }
}

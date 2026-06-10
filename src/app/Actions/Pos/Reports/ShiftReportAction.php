<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase B — Shift Report (Additions §1.2: extension of blueprint
 * §5.11.10 Staff Activity).
 *
 * One row per cashier shift OPENED in the window: branch, staff,
 * opened/closed times, opening float, expected cash (computed from
 * the cash tenders during the shift at close time), counted cash,
 * variance (negative = the drawer is SHORT — the follow-up case),
 * and cash collected (expected − opening). Open shifts show with
 * their float and no variance yet.
 *
 * Summary: shift count, closed count, total variance, total short
 * (only the negative variances — the real exposure number).
 *
 * Per-shift card sales / top products are a follow-up (they need a
 * payments-by-device-window join that deserves its own pass).
 */
final readonly class ShiftReportAction
{
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

        $query = DB::table('pos_shifts')
            ->join('pos_branches', 'pos_branches.id', '=', 'pos_shifts.branch_id')
            ->leftJoin('pos_staff', 'pos_staff.id', '=', 'pos_shifts.staff_id')
            ->where('pos_shifts.company_id', $companyId)
            ->whereBetween('pos_shifts.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $query->whereIn('pos_shifts.branch_id', $branchScope);
        }

        $rows = $query
            ->selectRaw('
                pos_shifts.id AS id,
                pos_shifts.uuid AS uuid,
                pos_shifts.status AS status,
                pos_shifts.opened_at AS opened_at,
                pos_shifts.closed_at AS closed_at,
                pos_shifts.opening_cash AS opening_cash,
                pos_shifts.expected_cash AS expected_cash,
                pos_shifts.closing_cash AS closing_cash,
                pos_shifts.variance AS variance,
                pos_branches.name AS branch_name,
                pos_staff.name AS staff_name
            ')
            ->orderByDesc('pos_shifts.opened_at')
            ->get();

        $shifts = $rows->map(static function ($r): array {
            $opening = (float) ($r->opening_cash ?? 0);
            $expected = $r->expected_cash !== null ? (float) $r->expected_cash : null;

            return [
                'id' => (int) $r->id,
                'uuid' => (string) $r->uuid,
                'status' => (string) $r->status,
                'branch_name' => (string) $r->branch_name,
                'staff_name' => $r->staff_name !== null ? (string) $r->staff_name : null,
                'opened_at' => (string) $r->opened_at,
                'closed_at' => $r->closed_at !== null ? (string) $r->closed_at : null,
                'opening_cash' => number_format($opening, 3, '.', ''),
                'expected_cash' => $expected !== null ? number_format($expected, 3, '.', '') : null,
                'counted_cash' => $r->closing_cash !== null
                    ? number_format((float) $r->closing_cash, 3, '.', '')
                    : null,
                'variance' => $r->variance !== null
                    ? number_format((float) $r->variance, 3, '.', '')
                    : null,
                // Cash COLLECTED during the shift (expected − opening float).
                'cash_collected' => $expected !== null
                    ? number_format($expected - $opening, 3, '.', '')
                    : null,
            ];
        })->all();

        $closed = array_filter($shifts, static fn (array $s): bool => $s['variance'] !== null);
        $totalVariance = array_sum(array_map(static fn (array $s): float => (float) $s['variance'], $closed));
        $totalShort = array_sum(array_map(
            static fn (array $s): float => min(0.0, (float) $s['variance']),
            $closed,
        ));

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'summary' => [
                'shift_count' => count($shifts),
                'closed_count' => count($closed),
                'total_variance' => number_format($totalVariance, 3, '.', ''),
                'total_short' => number_format($totalShort, 3, '.', ''),
            ],
            'shifts' => $shifts,
        ];
    }
}

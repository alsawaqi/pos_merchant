<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
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
 *
 * LAUNCH-P5 B5 — what pos_api now records at and after the close:
 *   - closed_by: who closed the drawer (at a handover, the next cashier);
 *   - payouts: cash paid out of the drawer during the shift (already taken
 *     off the expected cash at close);
 *   - late_sales: cash sales that reached the server AFTER the close but
 *     belong to the shift (the printed Z did not have them);
 *   - needs_review: the server flagged the shift (late sales, or a sale of
 *     this device that failed permanently; the note says which);
 *   - corrected expected cash = expected + late sales - late pay-outs
 *     (fix order 1, F7: drawer pay-outs that reached the server after the
 *     close), and the corrected variance = counted - corrected expected:
 *     the true drawer position;
 *   - reopenable: closed on today's Muscat business day (the re-open rule).
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
            ->leftJoin('pos_staff as closer', 'closer.id', '=', 'pos_shifts.closed_by_staff_id')
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
                pos_staff.name AS staff_name,
                pos_shifts.note AS note,
                pos_shifts.closed_by_staff_id AS closed_by_staff_id,
                closer.name AS closed_by_name,
                pos_shifts.close_device_id AS close_device_id,
                pos_shifts.needs_review AS needs_review,
                pos_shifts.late_sales_baisas AS late_sales_baisas,
                pos_shifts.payouts_baisas AS payouts_baisas,
                pos_shifts.late_payouts_baisas AS late_payouts_baisas
            ')
            ->orderByDesc('pos_shifts.opened_at')
            ->get();

        $now = Carbon::now();
        $shifts = $rows->map(static function ($r) use ($now): array {
            $opening = (float) ($r->opening_cash ?? 0);
            $expected = $r->expected_cash !== null ? (float) $r->expected_cash : null;
            $lateSales = ((int) ($r->late_sales_baisas ?? 0)) / 1000;
            $payouts = ((int) ($r->payouts_baisas ?? 0)) / 1000;
            $latePayouts = ((int) ($r->late_payouts_baisas ?? 0)) / 1000;
            $counted = $r->closing_cash !== null ? (float) $r->closing_cash : null;
            $correctedExpected = $expected !== null ? $expected + $lateSales - $latePayouts : null;

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
                // LAUNCH-P5 B5.
                'closed_by_staff_id' => $r->closed_by_staff_id !== null ? (int) $r->closed_by_staff_id : null,
                'closed_by_name' => $r->closed_by_name !== null ? (string) $r->closed_by_name : null,
                'close_device_id' => $r->close_device_id !== null ? (int) $r->close_device_id : null,
                'payouts' => number_format($payouts, 3, '.', ''),
                'late_sales' => number_format($lateSales, 3, '.', ''),
                'late_payouts' => number_format($latePayouts, 3, '.', ''),
                'needs_review' => (bool) $r->needs_review,
                'note' => $r->note !== null ? (string) $r->note : null,
                'corrected_expected_cash' => $correctedExpected !== null ? number_format($correctedExpected, 3, '.', '') : null,
                'corrected_variance' => $correctedExpected !== null && $counted !== null
                    ? number_format($counted - $correctedExpected, 3, '.', '')
                    : null,
                'reopenable' => (string) $r->status === 'closed' && $r->closed_at !== null
                    && BusinessTime::sameDay((string) $r->closed_at, $now),
            ];
        })->all();

        $closed = array_filter($shifts, static fn (array $s): bool => $s['variance'] !== null);
        $totalVariance = array_sum(array_map(static fn (array $s): float => (float) $s['variance'], $closed));
        $totalShort = array_sum(array_map(
            static fn (array $s): float => min(0.0, (float) $s['variance']),
            $closed,
        ));

        $corrected = array_filter($shifts, static fn (array $s): bool => $s['corrected_variance'] !== null);
        $totalCorrected = array_sum(array_map(static fn (array $s): float => (float) $s['corrected_variance'], $corrected));
        $totalCorrectedShort = array_sum(array_map(static fn (array $s): float => min(0.0, (float) $s['corrected_variance']), $corrected));

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
                // LAUNCH-P5 B5.
                'needs_review_count' => count(array_filter($shifts, static fn (array $s): bool => $s['needs_review'])),
                'total_payouts' => number_format(array_sum(array_map(static fn (array $s): float => (float) $s['payouts'], $shifts)), 3, '.', ''),
                'total_late_sales' => number_format(array_sum(array_map(static fn (array $s): float => (float) $s['late_sales'], $shifts)), 3, '.', ''),
                'total_late_payouts' => number_format(array_sum(array_map(static fn (array $s): float => (float) $s['late_payouts'], $shifts)), 3, '.', ''),
                'total_corrected_variance' => number_format($totalCorrected, 3, '.', ''),
                'total_corrected_short' => number_format($totalCorrectedShort, 3, '.', ''),
            ],
            'shifts' => $shifts,
        ];
    }
}

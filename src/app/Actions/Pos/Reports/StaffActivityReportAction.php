<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Staff Activity Report (blueprint §5.11.10).
 *
 *   Per staff:
 *     - orders rung (paid)
 *     - avg_ticket
 *     - voids
 *     - discounts_applied (orders with discount_total > 0)
 *     - hours_logged_in (Phase 8 Shifts sum closed_at - opened_at)
 */
final readonly class StaffActivityReportAction
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

        $base = DB::table('pos_orders')
            ->where('pos_orders.company_id', $companyId)
            ->whereNotNull('pos_orders.staff_id')
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $base->whereIn('pos_orders.branch_id', $branchScope);
        }

        $rows = (clone $base)
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_orders.staff_id')
            ->selectRaw('
                pos_staff.id AS staff_id,
                pos_staff.name AS staff_name,
                SUM(CASE WHEN pos_orders.status = ? THEN 1 ELSE 0 END) AS orders_paid,
                SUM(CASE WHEN pos_orders.status = ? THEN pos_orders.grand_total ELSE 0 END) AS revenue,
                SUM(CASE WHEN pos_orders.status = ? THEN 1 ELSE 0 END) AS voids,
                SUM(CASE WHEN pos_orders.status = ? AND pos_orders.discount_total > 0 THEN 1 ELSE 0 END) AS discounted
            ', [
                OrderStatus::Paid->value,
                OrderStatus::Paid->value,
                OrderStatus::Void->value,
                OrderStatus::Paid->value,
            ])
            ->groupBy('pos_staff.id', 'pos_staff.name')
            ->orderByDesc('revenue')
            ->get();

        // Shifts: hours logged in for closed shifts in window.
        $shiftRows = DB::table('pos_shifts')
            ->where('pos_shifts.company_id', $companyId)
            ->whereNotNull('pos_shifts.staff_id')
            ->whereNotNull('pos_shifts.closed_at')
            ->whereBetween('pos_shifts.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_shifts.branch_id', $branchScope))
            ->groupBy('pos_shifts.staff_id')
            ->selectRaw('
                pos_shifts.staff_id,
                COUNT(*) AS shift_count,
                SUM((strftime("%s", pos_shifts.closed_at) - strftime("%s", pos_shifts.opened_at))) AS total_seconds
            ')
            ->get()
            ->keyBy('staff_id');

        $driver = DB::connection()->getDriverName();
        // Postgres needs a different time-delta expression.
        if ($driver !== 'sqlite') {
            $shiftRows = DB::table('pos_shifts')
                ->where('pos_shifts.company_id', $companyId)
                ->whereNotNull('pos_shifts.staff_id')
                ->whereNotNull('pos_shifts.closed_at')
                ->whereBetween('pos_shifts.opened_at', [$filter->dateFrom, $filter->dateTo])
                ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_shifts.branch_id', $branchScope))
                ->groupBy('pos_shifts.staff_id')
                ->selectRaw('
                    pos_shifts.staff_id,
                    COUNT(*) AS shift_count,
                    EXTRACT(EPOCH FROM SUM(pos_shifts.closed_at - pos_shifts.opened_at)) AS total_seconds
                ')
                ->get()
                ->keyBy('staff_id');
        }

        $result = $rows->map(static function ($r) use ($shiftRows): array {
            $ordersPaid = (int) $r->orders_paid;
            $revenue = (float) $r->revenue;
            $avgTicket = $ordersPaid > 0 ? $revenue / $ordersPaid : 0.0;
            $shift = $shiftRows[$r->staff_id] ?? null;
            $hoursLogged = $shift !== null
                ? round(((float) $shift->total_seconds) / 3600, 2)
                : 0.0;

            return [
                'staff_id' => (int) $r->staff_id,
                'staff_name' => (string) $r->staff_name,
                'orders_paid' => $ordersPaid,
                'revenue' => number_format($revenue, 3, '.', ''),
                'avg_ticket' => number_format($avgTicket, 3, '.', ''),
                'voids' => (int) $r->voids,
                'discounts_applied' => (int) $r->discounted,
                'hours_logged' => $hoursLogged,
            ];
        })->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'rows' => $result,
        ];
    }
}

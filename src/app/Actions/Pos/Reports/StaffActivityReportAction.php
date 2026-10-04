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
 *     - voids — LAUNCH-P5 B3 (L5): voids this person PERFORMED
 *       (pos_orders.voided_by_staff_id, written by pos_api from P5 builds),
 *       no longer voided orders they happened to ring up. Orders voided
 *       before P5 carry no voider and are not counted.
 *     - discounts_applied (orders with discount_total > 0)
 *     - hours_logged_in (Phase 8 Shifts sum closed_at - opened_at)
 *
 * A person who only voided (rang nothing up) still gets a row.
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
                SUM(CASE WHEN pos_orders.status = ? AND pos_orders.discount_total > 0 THEN 1 ELSE 0 END) AS discounted
            ', [
                OrderStatus::Paid->value,
                OrderStatus::Paid->value,
                OrderStatus::Paid->value,
            ])
            ->groupBy('pos_staff.id', 'pos_staff.name')
            ->orderByDesc('revenue')
            ->get();

        // LAUNCH-P5 — voids performed, by the person who voided.
        $voids = DB::table('pos_orders')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Void->value)
            ->whereNotNull('pos_orders.voided_by_staff_id')
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchScope))
            ->groupBy('pos_orders.voided_by_staff_id')
            ->selectRaw('pos_orders.voided_by_staff_id AS staff_id, COUNT(*) AS voids')
            ->pluck('voids', 'staff_id')
            ->mapWithKeys(static fn ($n, $id): array => [(int) $id => (int) $n]);

        $voidOnly = $voids->keys()->diff($rows->pluck('staff_id')->map(fn ($id) => (int) $id))->values();
        if ($voidOnly->isNotEmpty()) {
            $names = DB::table('pos_staff')->where('company_id', $companyId)->whereIn('id', $voidOnly->all())->pluck('name', 'id');
            foreach ($voidOnly as $id) {
                if (isset($names[$id])) {
                    $rows->push((object) [
                        'staff_id' => $id,
                        'staff_name' => $names[$id],
                        'orders_paid' => 0,
                        'revenue' => 0,
                        'discounted' => 0,
                    ]);
                }
            }
        }

        // Shifts: hours logged in for closed shifts in window. The per-shift
        // duration-in-seconds expression differs by driver — sqlite (test
        // schema) has strftime; Postgres (prod) subtracts timestamps into an
        // interval and EXTRACTs the epoch. Build the right one and run ONE query.
        $driver = DB::connection()->getDriverName();
        $shiftSeconds = $driver === 'sqlite'
            ? "(strftime('%s', pos_shifts.closed_at) - strftime('%s', pos_shifts.opened_at))"
            : 'EXTRACT(EPOCH FROM (pos_shifts.closed_at - pos_shifts.opened_at))';

        $shiftRows = DB::table('pos_shifts')
            ->where('pos_shifts.company_id', $companyId)
            ->whereNotNull('pos_shifts.staff_id')
            ->whereNotNull('pos_shifts.closed_at')
            ->whereBetween('pos_shifts.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_shifts.branch_id', $branchScope))
            ->groupBy('pos_shifts.staff_id')
            ->selectRaw("
                pos_shifts.staff_id,
                COUNT(*) AS shift_count,
                SUM($shiftSeconds) AS total_seconds
            ")
            ->get()
            ->keyBy('staff_id');

        $result = $rows->map(static function ($r) use ($shiftRows, $voids): array {
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
                'voids' => (int) ($voids[(int) $r->staff_id] ?? 0),
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

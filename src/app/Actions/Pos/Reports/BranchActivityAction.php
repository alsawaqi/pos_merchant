<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Branch detail activity feed + sales snapshot (v2 #11). Caller passes
 * the already tenant-verified company + branch ids.
 *
 *   - sales            : today + month-to-date paid gross/count (branch)
 *   - hour_weekday     : (day-of-week × hour) paid-gross matrix over the
 *                        trailing window, for the branch "Sales by Hour"
 *                        performance heatmap
 *   - recent_orders    : last N orders at the branch (+ staff/customer)
 *   - recent_shifts    : last N shifts (+ staff, variance)
 *   - recent_movements : last N stock movements (+ ingredient, who)
 *
 * Money is decimal-3 OMR. Relations are eager-loaded to avoid N+1.
 */
final readonly class BranchActivityAction
{
    private const RECENT = 8;

    /** Trailing window (days) for the Sales-by-Hour performance heatmap. */
    private const HEATMAP_DAYS = 30;

    /** Trailing window (days) + size for the top-products / staff / trend cards. */
    private const WINDOW_DAYS = 30;

    private const TOP_N = 6;

    /**
     * @return array<string, mixed>
     */
    public function handle(int $companyId, int $branchId): array
    {
        return [
            'sales' => $this->salesSnapshot($companyId, $branchId),
            'hour_weekday' => $this->hourWeekday($companyId, $branchId),
            // Branch control-center analytics (trailing WINDOW_DAYS), each a
            // single branch-scoped aggregate the frontend charts: a top-products
            // donut, a staff revenue-share donut + most-active list, and a daily
            // sales-trend line.
            'top_products' => $this->topProducts($companyId, $branchId),
            'staff_activity' => $this->staffActivity($companyId, $branchId),
            'sales_trend' => $this->salesTrend($companyId, $branchId),
            'window_days' => self::WINDOW_DAYS,
            'recent_orders' => $this->recentOrders($companyId, $branchId),
            'recent_shifts' => $this->recentShifts($companyId, $branchId),
            'recent_movements' => $this->recentMovements($branchId),
        ];
    }

    /**
     * The trailing analytics window [from, to].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        $now = Carbon::now();

        return [$now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay(), $now->copy()->endOfDay()];
    }

    /**
     * Most-sold products at this branch over the window, by quantity (the pie).
     *
     * @return list<array{product_name: string, qty_sold: string, revenue: string}>
     */
    private function topProducts(int $companyId, int $branchId): array
    {
        [$from, $to] = $this->window();

        return DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.branch_id', $branchId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_order_items.product_name_snapshot AS product_name,
                COALESCE(SUM(pos_order_items.qty), 0) AS qty_sold,
                COALESCE(SUM(pos_order_items.line_total), 0) AS revenue
            ')
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name_snapshot')
            ->orderByDesc('qty_sold')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'product_name' => (string) ($r->product_name ?? ''),
                'qty_sold' => number_format((float) $r->qty_sold, 3, '.', ''),
                'revenue' => number_format((float) $r->revenue, 3, '.', ''),
            ])->all();
    }

    /**
     * Most-active staff at this branch over the window: paid orders + revenue.
     *
     * @return list<array{staff_name: string, orders_paid: int, revenue: string}>
     */
    private function staffActivity(int $companyId, int $branchId): array
    {
        [$from, $to] = $this->window();

        return DB::table('pos_orders')
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_orders.staff_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.branch_id', $branchId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereNotNull('pos_orders.staff_id')
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_staff.name AS staff_name,
                COUNT(*) AS orders_paid,
                COALESCE(SUM(pos_orders.grand_total), 0) AS revenue
            ')
            ->groupBy('pos_staff.id', 'pos_staff.name')
            ->orderByDesc('revenue')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'staff_name' => (string) ($r->staff_name ?? ''),
                'orders_paid' => (int) $r->orders_paid,
                'revenue' => number_format((float) $r->revenue, 3, '.', ''),
            ])->all();
    }

    /**
     * Zero-filled daily paid-gross series for this branch (the trend line).
     * Driver-aware date expression (sqlite tests vs Postgres prod).
     *
     * @return list<array{date: string, gross: string, count: int}>
     */
    private function salesTrend(int $companyId, int $branchId): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : "to_char(opened_at, 'YYYY-MM-DD')";

        [$start, $to] = $this->window();

        $rows = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$start, $to])
            ->selectRaw("$dayExpr AS day, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw($dayExpr)
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $d = $start->copy()->addDays($i)->format('Y-m-d');
            $r = $rows->get($d);
            $series[] = [
                'date' => $d,
                'gross' => number_format((float) ($r->gross ?? 0), 3, '.', ''),
                'count' => (int) ($r->cnt ?? 0),
            ];
        }

        return $series;
    }

    /**
     * (Day-of-week × hour) paid-gross matrix for this branch over the last
     * {@see self::HEATMAP_DAYS} days. Sparse: only buckets with paid orders;
     * the frontend zero-fills the 7×24 grid. Driver-aware (sqlite/Postgres).
     *
     * @return array{window_days: int, cells: list<array{weekday: int, hour: int, gross: string, count: int}>}
     */
    private function hourWeekday(int $companyId, int $branchId): array
    {
        $now = Carbon::now();
        $from = $now->copy()->subDays(self::HEATMAP_DAYS - 1)->startOfDay();
        $to = $now->copy()->endOfDay();

        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)::int';
        $dowExpr = $driver === 'sqlite'
            ? "CAST(strftime('%w', opened_at) AS INTEGER)"
            : 'EXTRACT(DOW FROM opened_at)::int';

        $cells = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw("$dowExpr AS weekday, $hourExpr AS hour, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw("$dowExpr, $hourExpr")
            ->orderByRaw("$dowExpr, $hourExpr")
            ->get()
            ->map(static fn ($r): array => [
                'weekday' => (int) $r->weekday,
                'hour' => (int) $r->hour,
                'gross' => number_format((float) $r->gross, 3, '.', ''),
                'count' => (int) $r->cnt,
            ])->all();

        return ['window_days' => self::HEATMAP_DAYS, 'cells' => $cells];
    }

    /**
     * @return array{today: array{gross: string, count: int}, mtd: array{gross: string, count: int}}
     */
    private function salesSnapshot(int $companyId, int $branchId): array
    {
        $now = Carbon::now();

        return [
            'today' => $this->snap($companyId, $branchId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'mtd' => $this->snap($companyId, $branchId, $now->copy()->startOfMonth(), $now->copy()->endOfDay()),
        ];
    }

    /**
     * @return array{gross: string, count: int}
     */
    private function snap(int $companyId, int $branchId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->first();

        return [
            'gross' => number_format((float) ($row?->gross ?? 0), 3, '.', ''),
            'count' => (int) ($row?->cnt ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(int $companyId, int $branchId): array
    {
        return Order::query()
            ->with(['staff:id,name', 'customer:id,name'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (Order $o): array => [
                'uuid' => $o->uuid,
                'status' => $o->status?->value,
                'order_type' => $o->order_type?->value,
                'grand_total' => (string) $o->grand_total,
                'opened_at' => $o->opened_at?->format('Y-m-d\TH:i:s'),
                'staff_name' => $o->staff?->name,
                'customer_name' => $o->customer?->name,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentShifts(int $companyId, int $branchId): array
    {
        return Shift::query()
            ->with(['staff:id,name'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (Shift $s): array => [
                'uuid' => $s->uuid,
                'status' => $s->status?->value,
                'opened_at' => $s->opened_at?->format('Y-m-d\TH:i:s'),
                'closed_at' => $s->closed_at?->format('Y-m-d\TH:i:s'),
                'variance' => $s->variance !== null ? (string) $s->variance : null,
                'staff_name' => $s->staff?->name,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMovements(int $branchId): array
    {
        return StockMovement::query()
            ->with(['ingredient:id,name,unit', 'recordedByUser:id,name', 'recordedByStaff:id,name'])
            ->where('branch_id', $branchId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static function (StockMovement $m): array {
                $unit = $m->ingredient?->unit;

                return [
                    'movement_type' => $m->movement_type?->value,
                    'quantity' => (string) $m->quantity,
                    'occurred_at' => $m->occurred_at?->format('Y-m-d\TH:i:s'),
                    'ingredient_name' => $m->ingredient?->name,
                    'unit' => $unit instanceof \BackedEnum ? $unit->value : ($unit !== null ? (string) $unit : null),
                    'recorded_by' => $m->recordedByUser?->name ?? $m->recordedByStaff?->name,
                ];
            })->all();
    }
}

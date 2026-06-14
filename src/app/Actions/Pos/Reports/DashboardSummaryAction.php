<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b-7 — merchant Dashboard summary (blueprint §5.2).
 *
 * Returns the snapshot the landing page renders today:
 *
 *   - today  : gross + order_count for the calendar day
 *   - yesterday: same shape; UI shows percentage delta
 *   - mtd    : gross + order_count for the current month so far
 *   - top_product_today: the highest-revenue product today
 *     (snapshot name; products may be renamed later)
 *   - low_stock_count: ingredients whose total branch balance
 *     is below their min_stock_threshold (across all branches
 *     the actor has scope to). NULL threshold = excluded.
 *   - recent_audit_events: latest 5 audit rows for the tenant
 *     (UI hides these for users without audit_log.view)
 *   - payment_mix_today: cash/card/split tender split for today's
 *     paid orders (blueprint §5.2 "Payment mix today")
 *   - roundup_today: successful charity round-up donations today
 *   - active_devices: tenant devices online (heartbeat within
 *     5 minutes) vs total
 *
 * One round trip through the controller. Each query is scoped
 * to the tenant; this is NOT a public report so it doesn't go
 * through ReportFilter.
 */
final readonly class DashboardSummaryAction
{
    /** Days of daily sales history fed to the trend chart. */
    private const TREND_DAYS = 14;

    /** Days of history for the "Sales by Hour" heatmap. */
    private const HEATMAP_DAYS = 30;

    /** Top-N size for the dashboard breakdown charts. */
    private const TOP_N = 5;

    /**
     * A device counts as "online" when its heartbeat (pos_api
     * /device/heartbeat → pos_devices.last_seen_at) is within this
     * many minutes. Matches the admin dashboard's window.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    /**
     * Movement types that represent ingredient CONSUMPTION (negative
     * quantities). Mirrors InventoryConsumptionReportAction so the
     * "top ingredients" widget agrees with the full report.
     */
    private const CONSUMPTION_TYPES = [
        StockMovementType::SaleConsumption->value,
        StockMovementType::AddOnConsumption->value,
        StockMovementType::Adjustment->value,
    ];

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * P-G5 — $branchIds is the actor's branch scope (NULL =
     * unrestricted): every widget below filters to it, so a
     * branch-restricted user's dashboard only aggregates THEIR
     * branches (incl. low-stock rollup, device fleet, audit feed —
     * company-level audit rows with no branch are hidden for them).
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function handle(?array $branchIds = null): array
    {
        $companyId = $this->tenant->requiredId();

        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $yesterdayEnd = $todayEnd->copy()->subDay();
        $monthStart = $now->copy()->startOfMonth();

        return [
            'today' => $this->salesSnapshot($companyId, $branchIds, $todayStart, $todayEnd),
            'yesterday' => $this->salesSnapshot($companyId, $branchIds, $yesterdayStart, $yesterdayEnd),
            'mtd' => $this->salesSnapshot($companyId, $branchIds, $monthStart, $todayEnd),
            'top_product_today' => $this->topProductInWindow($companyId, $branchIds, $todayStart, $todayEnd),
            'low_stock_count' => $this->lowStockCount($companyId, $branchIds),
            'recent_audit_events' => $this->recentAuditEvents($companyId, $branchIds),
            // §5.2 tiles: tender split + charity round-up for today,
            // plus the live device fleet snapshot.
            'payment_mix_today' => $this->paymentMixToday($companyId, $branchIds, $todayStart, $todayEnd),
            'roundup_today' => $this->roundupToday($companyId, $branchIds, $todayStart, $todayEnd),
            'active_devices' => $this->activeDevices($companyId, $branchIds),
            // v2 dashboard graphs (blueprint §5.2): a daily trend plus
            // MTD top-N breakdowns. Windows: trend = trailing TREND_DAYS,
            // every top-N = month-to-date.
            'sales_trend' => $this->salesTrend($companyId, $branchIds, self::TREND_DAYS),
            // Sales-by-hour (day-of-week × hour) heatmap over the trailing window.
            'hour_weekday' => $this->hourWeekday($companyId, $branchIds),
            'top_products' => $this->topProducts($companyId, $branchIds, $monthStart, $todayEnd),
            'top_branches' => $this->topBranches($companyId, $branchIds, $monthStart, $todayEnd),
            'top_customers' => $this->topCustomers($companyId, $branchIds, $monthStart, $todayEnd),
            'top_staff' => $this->topStaff($companyId, $branchIds, $monthStart, $todayEnd),
            'top_ingredients' => $this->topIngredients($companyId, $branchIds, $monthStart, $todayEnd),
        ];
    }

    /**
     * Daily paid-sales gross + count for the trailing $days, with
     * zero-filled gaps so the chart line stays continuous. Date
     * expression is driver-aware (sqlite tests vs Postgres prod).
     *
     * @return list<array{date: string, gross: string, count: int}>
     */
    private function salesTrend(int $companyId, ?array $branchIds, int $days): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : "to_char(opened_at, 'YYYY-MM-DD')";

        $now = Carbon::now();
        $start = $now->copy()->startOfDay()->subDays($days - 1);

        $rows = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$start, $now->copy()->endOfDay()])
            ->selectRaw("$dayExpr AS day, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw($dayExpr)
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
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
     * (Day-of-week × hour) paid-gross matrix over the trailing HEATMAP_DAYS, for
     * the dashboard "Sales by Hour" heatmap. Sparse — only buckets with paid
     * orders; the frontend zero-fills the 7×24 grid. Driver-aware (sqlite tests
     * vs Postgres prod). Branch-scoped.
     *
     * @param  list<int>|null  $branchIds
     * @return array{window_days: int, cells: list<array{weekday: int, hour: int, gross: string, count: int}>}
     */
    private function hourWeekday(int $companyId, ?array $branchIds): array
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
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
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
     * MTD top products by revenue (snapshot name).
     *
     * @return list<array{product_name: string, revenue: string}>
     */
    private function topProducts(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchIds))
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_order_items.product_id AS product_id,
                pos_order_items.product_name_snapshot AS product_name,
                COALESCE(SUM(pos_order_items.qty * pos_order_items.unit_price_snapshot), 0) AS revenue
            ')
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name_snapshot')
            ->orderByDesc('revenue')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'product_name' => (string) $r->product_name,
                'revenue' => number_format((float) $r->revenue, 3, '.', ''),
            ])->all();
    }

    /**
     * MTD top branches by gross.
     *
     * @return list<array{branch_name: string, gross: string}>
     */
    private function topBranches(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_orders')
            ->join('pos_branches', 'pos_branches.id', '=', 'pos_orders.branch_id')
            ->where('pos_orders.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchIds))
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('pos_branches.name AS branch_name, COALESCE(SUM(pos_orders.grand_total), 0) AS gross')
            ->groupBy('pos_branches.id', 'pos_branches.name')
            ->orderByDesc('gross')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'branch_name' => (string) $r->branch_name,
                'gross' => number_format((float) $r->gross, 3, '.', ''),
            ])->all();
    }

    /**
     * MTD top customers by spend (orders with a linked customer).
     *
     * @return list<array{customer_name: string, total_spend: string}>
     */
    private function topCustomers(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_orders')
            ->join('pos_customers', 'pos_customers.id', '=', 'pos_orders.customer_id')
            ->where('pos_orders.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchIds))
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereNotNull('pos_orders.customer_id')
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_customers.name AS customer_name,
                COALESCE(SUM(pos_orders.grand_total), 0) AS total_spend
            ')
            ->groupBy('pos_customers.id', 'pos_customers.name')
            ->orderByDesc('total_spend')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'customer_name' => (string) ($r->customer_name ?? ''),
                'total_spend' => number_format((float) $r->total_spend, 3, '.', ''),
            ])->all();
    }

    /**
     * MTD top staff by paid revenue (orders with a linked staff).
     *
     * @return list<array{staff_name: string, revenue: string}>
     */
    private function topStaff(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_orders')
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_orders.staff_id')
            ->where('pos_orders.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchIds))
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereNotNull('pos_orders.staff_id')
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_staff.name AS staff_name,
                COALESCE(SUM(pos_orders.grand_total), 0) AS revenue
            ')
            ->groupBy('pos_staff.id', 'pos_staff.name')
            ->orderByDesc('revenue')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'staff_name' => (string) $r->staff_name,
                'revenue' => number_format((float) $r->revenue, 3, '.', ''),
            ])->all();
    }

    /**
     * MTD top consumed ingredients (negative consumption movements).
     *
     * @return list<array{ingredient_name: string, unit: string, consumed: string}>
     */
    private function topIngredients(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', self::CONSUMPTION_TYPES)
            ->where('pos_stock_movements.quantity', '<', 0)
            // P-G4 — exclude central-warehouse rows (branch_id NULL).
            ->whereNotNull('pos_stock_movements.branch_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchIds))
            ->whereBetween('pos_stock_movements.occurred_at', [$from, $to])
            ->selectRaw('
                pos_ingredients.name AS ingredient_name,
                pos_ingredients.unit AS unit,
                ABS(SUM(pos_stock_movements.quantity)) AS consumed
            ')
            ->groupBy('pos_ingredients.id', 'pos_ingredients.name', 'pos_ingredients.unit')
            ->orderByDesc('consumed')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'ingredient_name' => (string) $r->ingredient_name,
                'unit' => (string) $r->unit,
                'consumed' => number_format((float) $r->consumed, 3, '.', ''),
            ])->all();
    }

    /**
     * Today's tender split (blueprint §5.2 "Payment mix today").
     * Same joinSub shape as SalesReportAction::byPaymentMethod —
     * successful payments attached to today's paid orders.
     *
     * @return list<array{method: string, amount: string, count: int}>
     */
    private function paymentMixToday(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        $paidToday = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->select('id');

        return DB::table('pos_payments')
            ->joinSub($paidToday, 'orders', 'orders.id', '=', 'pos_payments.order_id')
            ->where('pos_payments.status', 'success')
            ->selectRaw('pos_payments.method AS method, COALESCE(SUM(pos_payments.amount), 0) AS amount, COUNT(*) AS cnt')
            ->groupBy('pos_payments.method')
            ->orderBy('pos_payments.method')
            ->get()
            ->map(static fn ($r): array => [
                'method' => (string) $r->method,
                'amount' => number_format((float) $r->amount, 3, '.', ''),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Today's successful charity round-up donations (§5.2 "Round-up
     * today"). Success only — money actually collected; the full
     * Round-Up report breaks down pending/failed. Same occurred_at
     * semantics as RoundUpDonationReportAction.
     *
     * @return array{total: string, count: int}
     */
    private function roundupToday(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        $row = DB::table('pos_roundup_donations')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('status', 'success')
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->first();

        return [
            'total' => number_format((float) ($row?->total ?? 0), 3, '.', ''),
            'count' => (int) ($row?->cnt ?? 0),
        ];
    }

    /**
     * Tenant device fleet snapshot (§5.2 "Active devices"). Online =
     * heartbeat (last_seen_at, written by pos_api) within the window;
     * NULL last_seen_at = never seen = offline. Soft-deleted
     * (decommission-erased) devices are excluded.
     *
     * @return array{online: int, total: int}
     */
    private function activeDevices(int $companyId, ?array $branchIds): array
    {
        $row = DB::table('pos_devices')
            ->where('company_id', $companyId)
            // P-G5 — scoped users count only THEIR branches' devices
            // (unassigned devices have branch_id NULL and drop out).
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereNull('deleted_at')
            ->selectRaw(
                'COUNT(*) AS total, COALESCE(SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END), 0) AS online',
                [Carbon::now()->subMinutes(self::ONLINE_WINDOW_MINUTES)],
            )
            ->first();

        return [
            'online' => (int) ($row?->online ?? 0),
            'total' => (int) ($row?->total ?? 0),
        ];
    }

    /**
     * @return array{gross: string, order_count: int}
     */
    private function salesSnapshot(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        $row = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) AS gross,
                COUNT(*) AS order_count
            ')
            ->first();

        return [
            'gross' => number_format((float) ($row?->gross ?? 0), 3, '.', ''),
            'order_count' => (int) ($row?->order_count ?? 0),
        ];
    }

    /**
     * Top product in the window by SUM(qty * unit_price_at_set).
     * Returns null if no paid orders exist in the window.
     *
     * @return array{product_id: int|null, product_name: string, revenue: string}|null
     */
    private function topProductInWindow(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): ?array
    {
        $row = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchIds))
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->selectRaw('
                pos_order_items.product_id AS product_id,
                pos_order_items.product_name_snapshot AS product_name,
                COALESCE(SUM(pos_order_items.qty * pos_order_items.unit_price_snapshot), 0) AS revenue
            ')
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name_snapshot')
            ->orderByDesc('revenue')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_name' => (string) $row->product_name,
            'revenue' => number_format((float) $row->revenue, 3, '.', ''),
        ];
    }

    /**
     * Count of ingredients with a non-null min_stock_threshold
     * whose summed balance across the tenant's branches falls
     * below it. The Inventory Consumption report shows the SAME
     * notion per-branch; this widget is the rollup.
     */
    private function lowStockCount(int $companyId, ?array $branchIds): int
    {
        // Sum balances per ingredient first (subquery), then
        // join the ingredient master to compare against threshold.
        // P-G5 — a scoped user's "low" sums across THEIR branches only.
        $balances = DB::table('pos_branch_stock')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_branch_stock.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_branch_stock.branch_id', $branchIds))
            ->whereNotNull('pos_ingredients.min_stock_threshold')
            ->selectRaw('
                pos_ingredients.id AS ingredient_id,
                pos_ingredients.min_stock_threshold AS threshold,
                SUM(pos_branch_stock.quantity) AS balance
            ')
            ->groupBy('pos_ingredients.id', 'pos_ingredients.min_stock_threshold')
            ->get();

        return $balances
            ->filter(static fn ($r): bool => (float) $r->balance < (float) $r->threshold)
            ->count();
    }

    /**
     * @return list<array{id: int, event: string, actor_name: string|null, created_at: string|null}>
     */
    private function recentAuditEvents(int $companyId, ?array $branchIds): array
    {
        return DB::table('pos_audit_logs')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_audit_logs.actor_user_id')
            ->where('pos_audit_logs.company_id', $companyId)
            // P-G5 — scoped users see only their branches' events;
            // company-level rows (branch_id NULL) stay HQ-only.
            ->when($branchIds !== null, fn ($q) => $q->whereIn('pos_audit_logs.branch_id', $branchIds))
            ->orderByDesc('pos_audit_logs.created_at')
            ->orderByDesc('pos_audit_logs.id')
            ->limit(5)
            ->selectRaw('
                pos_audit_logs.id AS id,
                pos_audit_logs.event AS event,
                pos_users.name AS actor_name,
                pos_audit_logs.created_at AS created_at
            ')
            ->get()
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'event' => (string) $r->event,
                'actor_name' => $r->actor_name !== null ? (string) $r->actor_name : null,
                'created_at' => $r->created_at !== null
                    ? Carbon::parse($r->created_at)->format('Y-m-d\TH:i:s')
                    : null,
            ])->all();
    }
}

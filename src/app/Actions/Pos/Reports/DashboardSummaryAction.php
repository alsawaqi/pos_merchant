<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
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
 *
 * One round trip through the controller. Each query is scoped
 * to the tenant; this is NOT a public report so it doesn't go
 * through ReportFilter.
 */
final readonly class DashboardSummaryAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $companyId = $this->tenant->requiredId();

        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $yesterdayEnd = $todayEnd->copy()->subDay();
        $monthStart = $now->copy()->startOfMonth();

        return [
            'today' => $this->salesSnapshot($companyId, $todayStart, $todayEnd),
            'yesterday' => $this->salesSnapshot($companyId, $yesterdayStart, $yesterdayEnd),
            'mtd' => $this->salesSnapshot($companyId, $monthStart, $todayEnd),
            'top_product_today' => $this->topProductInWindow($companyId, $todayStart, $todayEnd),
            'low_stock_count' => $this->lowStockCount($companyId),
            'recent_audit_events' => $this->recentAuditEvents($companyId),
        ];
    }

    /**
     * @return array{gross: string, order_count: int}
     */
    private function salesSnapshot(int $companyId, Carbon $from, Carbon $to): array
    {
        $row = DB::table('pos_orders')
            ->where('company_id', $companyId)
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
    private function topProductInWindow(int $companyId, Carbon $from, Carbon $to): ?array
    {
        $row = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
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
    private function lowStockCount(int $companyId): int
    {
        // Sum balances per ingredient first (subquery), then
        // join the ingredient master to compare against threshold.
        $balances = DB::table('pos_branch_stock')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_branch_stock.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
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
    private function recentAuditEvents(int $companyId): array
    {
        return DB::table('pos_audit_logs')
            ->leftJoin('pos_users', 'pos_users.id', '=', 'pos_audit_logs.actor_user_id')
            ->where('pos_audit_logs.company_id', $companyId)
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

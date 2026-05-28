<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Product Performance Report (blueprint §5.11.2).
 *
 *   - Top sellers by QUANTITY
 *   - Top sellers by REVENUE
 *   - Slow movers (sold < N times in window; N defaults to 3)
 *   - Per product: qty_sold, revenue, recipe_cost (Phase 8
 *     placeholder = 0), profit, margin_pct
 *   - Drill-down: which add-ons attach most to each top product
 *     (Phase 7b-3 ships top 5 add-ons across all products;
 *     per-product drill-down lands in 7b-6 UI)
 */
final readonly class ProductPerformanceReportAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter, int $slowMoverThreshold = 3): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();

        // Base: paid order items in the window. Join through
        // orders to apply the window filter.
        $itemsBase = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $itemsBase->whereIn('pos_orders.branch_id', $branchScope);
        }

        // Per-product aggregate.
        $perProduct = (clone $itemsBase)
            ->join('pos_products', 'pos_products.id', '=', 'pos_order_items.product_id')
            ->selectRaw('
                pos_products.id AS product_id,
                pos_products.name AS product_name,
                COALESCE(SUM(pos_order_items.qty), 0) AS qty_sold,
                COALESCE(SUM(pos_order_items.line_total), 0) AS revenue
            ')
            ->groupBy('pos_products.id', 'pos_products.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(static function ($r): array {
                $qty = (float) $r->qty_sold;
                $revenue = (float) $r->revenue;
                // Phase 8 will populate recipe_cost from
                // order_items.recipe_snapshot_json. For now
                // we return 0 + a note in _phase so the UI
                // can render the stub.
                $cost = 0.0;
                $profit = $revenue - $cost;
                $marginPct = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0;
                return [
                    'product_id' => (int) $r->product_id,
                    'product_name' => (string) $r->product_name,
                    'qty_sold' => number_format($qty, 3, '.', ''),
                    'revenue' => number_format($revenue, 3, '.', ''),
                    'recipe_cost' => number_format($cost, 3, '.', ''),
                    'profit' => number_format($profit, 3, '.', ''),
                    'margin_pct' => $marginPct,
                ];
            });

        // Top 10 by qty + top 10 by revenue (just re-order the
        // same payload).
        $topByQty = $perProduct->sortByDesc(static fn (array $r): float => (float) $r['qty_sold'])
            ->take(10)
            ->values()
            ->all();
        $topByRevenue = $perProduct->take(10)->values()->all();

        // Slow movers: qty_sold < threshold.
        $slowMovers = $perProduct->filter(static fn (array $r): bool => (float) $r['qty_sold'] < $slowMoverThreshold)
            ->sortBy(static fn (array $r): float => (float) $r['qty_sold'])
            ->take(20)
            ->values()
            ->all();

        // Top add-ons attached overall in the window.
        $topAddons = DB::table('pos_order_item_addons')
            ->join('pos_order_items', 'pos_order_items.id', '=', 'pos_order_item_addons.order_item_id')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchScope))
            ->selectRaw('
                pos_order_item_addons.add_on_name_snapshot AS add_on_name,
                COUNT(*) AS attach_count,
                COALESCE(SUM(pos_order_item_addons.price_delta_snapshot), 0) AS attach_revenue
            ')
            ->groupBy('pos_order_item_addons.add_on_name_snapshot')
            ->orderByDesc('attach_count')
            ->limit(10)
            ->get()
            ->map(static fn ($r): array => [
                'add_on_name' => (string) $r->add_on_name,
                'attach_count' => (int) $r->attach_count,
                'attach_revenue' => number_format((float) $r->attach_revenue, 3, '.', ''),
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'top_by_qty' => $topByQty,
            'top_by_revenue' => $topByRevenue,
            'slow_movers' => $slowMovers,
            'slow_mover_threshold' => $slowMoverThreshold,
            'top_addons' => $topAddons,
            '_phase' => [
                'recipe_cost_stub' => 'recipe_cost = 0 + margin_pct = 100% until Phase 8 fills the recipe_snapshot_json cost path.',
            ],
        ];
    }
}

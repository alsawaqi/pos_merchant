<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\RecipeSnapshotCost;
use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Query\Builder;
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

        // Per-product COGS from the snapshotted recipes (see RecipeSnapshotCost).
        $costByProduct = $this->costByProduct($itemsBase);

        // P-G3 — product-as-add-on sales count into the product's numbers
        // (agreed default): units = parent line qty per attach, revenue =
        // the add-on price x parent qty. Keyed by the frozen
        // linked_product_id, so later add-on edits can't rewrite history.
        $addonSales = DB::table('pos_order_item_addons')
            ->join('pos_order_items', 'pos_order_items.id', '=', 'pos_order_item_addons.order_item_id')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchScope))
            ->whereNotNull('pos_order_item_addons.linked_product_id')
            ->selectRaw('
                pos_order_item_addons.linked_product_id AS product_id,
                COALESCE(SUM(pos_order_items.qty), 0) AS addon_units,
                COALESCE(SUM(pos_order_item_addons.price_delta_snapshot * pos_order_items.qty), 0) AS addon_revenue
            ')
            ->groupBy('pos_order_item_addons.linked_product_id')
            ->get()
            ->keyBy('product_id');

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
            ->map(static function ($r) use ($costByProduct, $addonSales): array {
                $qty = (float) $r->qty_sold;
                $revenue = (float) $r->revenue;
                // recipe_cost from the line recipe snapshots (Phase 8 data).
                $cost = ($costByProduct[(int) $r->product_id] ?? 0) / 1000;
                $profit = $revenue - $cost;
                $marginPct = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0;
                $addon = $addonSales->get((int) $r->product_id);

                return [
                    'product_id' => (int) $r->product_id,
                    'product_name' => (string) $r->product_name,
                    'qty_sold' => number_format($qty, 3, '.', ''),
                    'revenue' => number_format($revenue, 3, '.', ''),
                    'recipe_cost' => number_format($cost, 3, '.', ''),
                    'profit' => number_format($profit, 3, '.', ''),
                    'margin_pct' => $marginPct,
                    // P-G3 — sold as an add-on inside other products.
                    'addon_units' => number_format((float) ($addon->addon_units ?? 0), 3, '.', ''),
                    'addon_revenue' => number_format((float) ($addon->addon_revenue ?? 0), 3, '.', ''),
                ];
            });

        // P-G3 — products sold ONLY as add-ons in the window still earn a
        // row (qty_sold 0, the add-on columns carry the story).
        $standaloneIds = $perProduct->pluck('product_id')->all();
        $addonOnlyIds = $addonSales->keys()->reject(fn ($id) => in_array((int) $id, $standaloneIds, true))->all();
        if ($addonOnlyIds !== []) {
            $names = DB::table('pos_products')->whereIn('id', $addonOnlyIds)->pluck('name', 'id');
            foreach ($addonOnlyIds as $productId) {
                $addon = $addonSales->get($productId);
                $perProduct->push([
                    'product_id' => (int) $productId,
                    'product_name' => (string) ($names[$productId] ?? ('#'.$productId)),
                    'qty_sold' => '0.000',
                    'revenue' => '0.000',
                    'recipe_cost' => '0.000',
                    'profit' => '0.000',
                    'margin_pct' => 0.0,
                    'addon_units' => number_format((float) $addon->addon_units, 3, '.', ''),
                    'addon_revenue' => number_format((float) $addon->addon_revenue, 3, '.', ''),
                ]);
            }
        }

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
        ];
    }

    /**
     * Per-product COGS (baisas) from the line recipe snapshots.
     *
     * @param  Builder  $itemsBase
     * @return array<int, int> product_id => cogs_baisas
     */
    private function costByProduct($itemsBase): array
    {
        $rows = (clone $itemsBase)
            ->select('pos_order_items.product_id', 'pos_order_items.qty', 'pos_order_items.recipe_snapshot_json')
            ->get();

        $cost = [];
        foreach ($rows as $row) {
            if ($row->product_id === null) {
                continue;
            }
            $pid = (int) $row->product_id;
            $cost[$pid] = ($cost[$pid] ?? 0) + RecipeSnapshotCost::itemBaisas($row->recipe_snapshot_json, (float) $row->qty);
        }

        return $cost;
    }
}

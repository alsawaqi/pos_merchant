<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\OrderLineCost;
use App\Actions\Pos\Reports\Support\RevenueSql;
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

        // Per-product COGS from the frozen order-line copies (LAUNCH-P3 P3-5).
        // LAUNCH-P4 B8 — a combo's cost is its child lines' cost.
        $costByProduct = $this->costByProduct($itemsBase, $companyId);

        // LAUNCH-P4 B8 — items that went out inside combos: the child lines'
        // quantity (parent qty × component qty), per chosen product. Never
        // revenue (the combo line carries it).
        $insideCombos = (clone $itemsBase)
            ->whereNotNull('pos_order_items.parent_order_item_id')
            ->whereNotNull('pos_order_items.product_id')
            ->selectRaw('pos_order_items.product_id AS product_id, COALESCE(SUM(pos_order_items.qty), 0) AS qty')
            ->groupBy('pos_order_items.product_id')
            ->pluck('qty', 'product_id');

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

        // Per-product aggregate. LAUNCH-P4 B8 — top-level lines only (a combo
        // is its own product; its items are counted in inside_combos_qty),
        // revenue excluding VAT.
        $perProduct = RevenueSql::topLevel((clone $itemsBase))
            ->join('pos_products', 'pos_products.id', '=', 'pos_order_items.product_id')
            ->selectRaw('
                pos_products.id AS product_id,
                pos_products.name AS product_name,
                pos_products.product_type AS product_type,
                COALESCE(SUM(pos_order_items.qty), 0) AS qty_sold,
                COALESCE(SUM('.RevenueSql::lineRevenue().'), 0) AS revenue
            ')
            ->groupBy('pos_products.id', 'pos_products.name', 'pos_products.product_type')
            ->orderByDesc('revenue')
            ->get()
            ->map(static function ($r) use ($costByProduct, $addonSales, $insideCombos): array {
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
                    'product_type' => (string) ($r->product_type ?? 'standard'),
                    'qty_sold' => number_format($qty, 3, '.', ''),
                    // LAUNCH-P4 B8 — the same item that went out inside combos.
                    'inside_combos_qty' => number_format((float) ($insideCombos[(int) $r->product_id] ?? 0), 3, '.', ''),
                    'revenue' => number_format($revenue, 3, '.', ''),
                    'recipe_cost' => number_format($cost, 3, '.', ''),
                    'profit' => number_format($profit, 3, '.', ''),
                    'margin_pct' => $marginPct,
                    // P-G3 — sold as an add-on inside other products.
                    'addon_units' => number_format((float) ($addon->addon_units ?? 0), 3, '.', ''),
                    'addon_revenue' => number_format((float) ($addon->addon_revenue ?? 0), 3, '.', ''),
                ];
            });

        // LAUNCH combo add-on, fix order 1 (C-6) — a meal line has no product
        // (its main is a child): its revenue (the paid line total, like any
        // top-level line) shows under the meal's name ("Beef burger meal");
        // its cost is its children's cost.
        $mealRows = RevenueSql::topLevel((clone $itemsBase))
            ->whereNotNull('pos_order_items.meal_id')
            ->whereNull('pos_order_items.product_id')
            ->selectRaw('
                pos_order_items.product_name_snapshot AS meal_name,
                COALESCE(SUM(pos_order_items.qty), 0) AS qty_sold,
                COALESCE(SUM('.RevenueSql::lineRevenue().'), 0) AS revenue
            ')
            ->groupBy('pos_order_items.product_name_snapshot')
            ->get();
        foreach ($mealRows as $r) {
            $revenue = (float) $r->revenue;
            $cost = ($costByProduct['meal:'.$r->meal_name] ?? 0) / 1000;
            $profit = $revenue - $cost;
            $perProduct->push([
                'product_id' => null,
                'row_key' => 'meal:'.$r->meal_name,
                'product_name' => (string) $r->meal_name,
                'product_type' => 'meal',
                'qty_sold' => number_format((float) $r->qty_sold, 3, '.', ''),
                'inside_combos_qty' => '0.000',
                'revenue' => number_format($revenue, 3, '.', ''),
                'recipe_cost' => number_format($cost, 3, '.', ''),
                'profit' => number_format($profit, 3, '.', ''),
                'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0,
                'addon_units' => '0.000',
                'addon_revenue' => '0.000',
            ]);
        }
        $perProduct = $perProduct->sortByDesc(static fn (array $r): float => (float) $r['revenue'])->values();

        // P-G3 — products sold ONLY as add-ons in the window still earn a
        // row (qty_sold 0, the add-on columns carry the story). LAUNCH-P4 —
        // and products that went out ONLY inside combos.
        $standaloneIds = $perProduct->pluck('product_id')->filter()->all();
        $otherIds = collect($addonSales->keys())->merge($insideCombos->keys())
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id) => in_array($id, $standaloneIds, true))
            ->values()
            ->all();
        if ($otherIds !== []) {
            $names = DB::table('pos_products')->whereIn('id', $otherIds)->pluck('name', 'id');
            foreach ($otherIds as $productId) {
                $addon = $addonSales->get($productId);
                $perProduct->push([
                    'product_id' => $productId,
                    'product_name' => (string) ($names[$productId] ?? ('#'.$productId)),
                    'product_type' => 'standard',
                    'qty_sold' => '0.000',
                    'inside_combos_qty' => number_format((float) ($insideCombos[$productId] ?? 0), 3, '.', ''),
                    'revenue' => '0.000',
                    'recipe_cost' => '0.000',
                    'profit' => '0.000',
                    'margin_pct' => 0.0,
                    'addon_units' => number_format((float) ($addon->addon_units ?? 0), 3, '.', ''),
                    'addon_revenue' => number_format((float) ($addon->addon_revenue ?? 0), 3, '.', ''),
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
     * Per-product COGS (baisas). LAUNCH-P3 P3-5 — the complete food cost of
     * each line from its frozen copies ({@see OrderLineCost}): recipe,
     * packaging, add-on option lines, add-ons that are products and cooked /
     * bought-in pieces, attributed to the line's product (its revenue —
     * line_total — includes the add-ons too).
     *
     * LAUNCH packaging add-on — each line with only the lines ticked for its
     * order's type. The per-order packaging is NOT attributed to any product
     * (it belongs to the whole order): it is in the Sales report's COGS only.
     *
     * @param  Builder  $itemsBase
     * @return array<int|string, int> product_id (or 'meal:<name>') => cogs_baisas
     */
    private function costByProduct($itemsBase, int $companyId): array
    {
        // LAUNCH-P4 B8 — every line, children included; a child line's cost
        // goes to its combo (the parent line's product): COGS of a combo =
        // the cost of the items chosen in it.
        $rows = (clone $itemsBase)
            ->leftJoin('pos_order_items as combo_parent', 'combo_parent.id', '=', 'pos_order_items.parent_order_item_id')
            ->select(
                'pos_order_items.id',
                'pos_order_items.product_id',
                'pos_order_items.qty',
                'pos_order_items.recipe_snapshot_json',
                'pos_order_items.component_snapshot_json',
                'pos_orders.branch_id',
                // LAUNCH packaging add-on — the "Used for" filter.
                'pos_orders.order_type',
                'pos_orders.stock_order_type',
            )
            ->selectRaw('COALESCE(combo_parent.product_id, pos_order_items.product_id) AS cost_product_id')
            // Fix order 1 (C-6) — a meal's children cost go to the meal row.
            ->selectRaw('combo_parent.meal_id AS parent_meal_id, combo_parent.product_name_snapshot AS parent_name')
            ->selectRaw('COALESCE(pos_orders.closed_at, pos_orders.opened_at) AS sold_at')
            ->get();

        $costs = (new OrderLineCost($companyId))->costs($rows);

        $cost = [];
        foreach ($rows as $row) {
            if ($row->parent_meal_id !== null) {
                $key = 'meal:'.$row->parent_name;
                $cost[$key] = ($cost[$key] ?? 0) + ($costs[(int) $row->id]['total'] ?? 0);

                continue;
            }
            if ($row->cost_product_id === null) {
                continue;
            }
            $pid = (int) $row->cost_product_id;
            $cost[$pid] = ($cost[$pid] ?? 0) + ($costs[(int) $row->id]['total'] ?? 0);
        }

        return $cost;
    }
}

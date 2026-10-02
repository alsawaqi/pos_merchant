<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\OrderLineCost;
use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Models\Product;
use App\Support\MerchantTenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Recipe & Cost Analysis Report (blueprint §5.11.4).
 *
 * One row per product WITH a recipe (made-to-order or cooked):
 *
 *   theoretical_cost   today's recipe at today's ingredient costs (prep items
 *                      costed through their recipes — LAUNCH-P3 P3-4), with
 *                      the price, profit and margin it implies. Recipes are
 *                      company-wide: the date and branch filters do not
 *                      change these columns.
 *
 * LAUNCH-P3 P3-5 — the date and branch filters APPLY to the sold columns
 * (decision: respect the filters rather than remove them):
 *
 *   units_sold, revenue    the paid order lines of the product in the window
 *                          at the selected branches;
 *   actual_cost_per_unit   what those lines' own recipe cost when they were
 *                          sold, from their frozen copies (made-to-order:
 *                          the recipe snapshot; cooked: the batch cost per
 *                          piece — {@see OrderLineCost}); NULL when none sold;
 *   cost_change_per_unit   actual − theoretical (+ = the sales cost more
 *                          than today's recipe would).
 *
 * Options, packaging and add-ons are deliberately left out of the actual
 * column so it compares like-for-like with the recipe; the Sales and Product
 * performance reports carry the complete line cost.
 */
final readonly class RecipeCostReportAction
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

        $products = Product::query()
            ->where('company_id', $companyId)
            ->with(['recipeLines.ingredient'])
            ->get()
            ->filter(static fn (Product $p): bool => $p->recipeLines->isNotEmpty());

        $sold = $this->sold($companyId, $filter, $branchScope, $products->modelKeys());

        $rows = $products->map(static function (Product $p) use ($sold): array {
            $theoretical = BigDecimal::of($p->theoreticalCost());
            $price = BigDecimal::of((string) $p->base_price);
            $profit = $price->minus($theoretical);
            $marginPct = $price->isPositive()
                ? (float) (string) $profit->multipliedBy(100)->dividedBy($price, 2, RoundingMode::HALF_UP)
                : 0.0;

            $line = $sold[(int) $p->id] ?? null;
            $units = $line !== null ? BigDecimal::of($line['units']) : BigDecimal::zero();
            $actual = $units->isPositive()
                ? BigDecimal::of($line['recipe_baisas'])->dividedBy(1000, 3)->dividedBy($units, 3, RoundingMode::HALF_UP)
                : null;

            return [
                'product_id' => $p->id,
                'product_name' => $p->name,
                'stock_mode' => $p->stock_mode,
                'base_price' => (string) $price->toScale(3, RoundingMode::HALF_UP),
                'theoretical_cost' => (string) $theoretical->toScale(3, RoundingMode::HALF_UP),
                'profit_per_unit' => (string) $profit->toScale(3, RoundingMode::HALF_UP),
                'margin_pct' => $marginPct,
                'recipe_line_count' => $p->recipeLines->count(),
                'units_sold' => (string) $units->toScale(3, RoundingMode::HALF_UP),
                'revenue' => $line !== null ? (string) BigDecimal::of($line['revenue'])->toScale(3, RoundingMode::HALF_UP) : '0.000',
                'actual_cost_per_unit' => $actual !== null ? (string) $actual : null,
                'cost_change_per_unit' => $actual !== null ? (string) $actual->minus($theoretical)->toScale(3, RoundingMode::HALF_UP) : null,
            ];
        })->sortByDesc(static fn (array $r): float => $r['margin_pct'])
            ->values()
            ->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Paid lines of these products in the window + branch scope.
     *
     * @param  list<int>|null  $branchScope
     * @param  list<int|string>  $productIds
     * @return array<int, array{units: string, revenue: string, recipe_baisas: int}>
     */
    private function sold(int $companyId, ReportFilter $filter, ?array $branchScope, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, static fn ($q) => $q->whereIn('pos_orders.branch_id', $branchScope))
            ->whereIn('pos_order_items.product_id', $productIds)
            ->select(
                'pos_order_items.id',
                'pos_order_items.product_id',
                'pos_order_items.qty',
                'pos_order_items.line_total',
                'pos_order_items.recipe_snapshot_json',
                'pos_order_items.component_snapshot_json',
                'pos_orders.branch_id',
            )
            ->selectRaw('COALESCE(pos_orders.closed_at, pos_orders.opened_at) AS sold_at')
            ->get();

        $costs = (new OrderLineCost($companyId))->costs($rows);

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            $out[$pid] ??= ['units' => '0', 'revenue' => '0', 'recipe_baisas' => 0];
            $out[$pid]['units'] = (string) BigDecimal::of($out[$pid]['units'])->plus((string) $row->qty);
            $out[$pid]['revenue'] = (string) BigDecimal::of($out[$pid]['revenue'])->plus((string) $row->line_total);
            $out[$pid]['recipe_baisas'] += $costs[(int) $row->id]['recipe'] ?? 0;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Models\Ingredient;
use App\Models\Product;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Recipe & Cost Analysis Report (blueprint §5.11.4).
 *
 *   - Per product (WITH recipe): theoretical cost from CURRENT
 *     ingredient prices, average actual snapshotted cost,
 *     sale price, margin
 *   - Trend: how product cost has moved month over month
 *     (Phase 8 ships the historical snapshot path; for now we
 *     return the current snapshot only)
 *   - Only present for products with a recipe -- confirms the
 *     optionality
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
        // Branch scope doesn't really apply to recipe cost
        // (recipes are company-level); we accept the filter
        // for shape consistency but ignore the branch axis.

        $products = Product::query()
            ->where('company_id', $companyId)
            ->with(['recipeLines.ingredient'])
            ->get()
            ->filter(static fn (Product $p): bool => $p->recipeLines->isNotEmpty());

        $rows = $products->map(static function (Product $p): array {
            // Theoretical cost = SUM(line.quantity *
            // ingredient.default_unit_cost) at TODAY's prices.
            $theoreticalCost = 0.0;
            foreach ($p->recipeLines as $line) {
                $ingredient = $line->ingredient;
                if ($ingredient === null) {
                    continue;
                }
                $theoreticalCost += (float) $line->quantity * (float) $ingredient->default_unit_cost;
            }

            $price = (float) $p->base_price;
            $profit = $price - $theoreticalCost;
            $marginPct = $price > 0 ? round(($profit / $price) * 100, 2) : 0.0;

            return [
                'product_id' => $p->id,
                'product_name' => $p->name,
                'base_price' => number_format($price, 3, '.', ''),
                'theoretical_cost' => number_format($theoreticalCost, 3, '.', ''),
                'profit_per_unit' => number_format($profit, 3, '.', ''),
                'margin_pct' => $marginPct,
                'recipe_line_count' => $p->recipeLines->count(),
            ];
        })->sortByDesc(static fn (array $r): float => $r['margin_pct'])
            ->values()
            ->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
            ],
            'rows' => $rows,
            '_phase' => [
                'trend_stub' => 'Month-over-month cost trend lands with Phase 8 historical recipe snapshots.',
            ],
        ];
    }
}

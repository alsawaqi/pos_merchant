<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Inventory Consumption Report (blueprint §5.11.3).
 *
 *   - Per ingredient: total consumed in window, by branch
 *   - Days-of-stock remaining at current consumption rate
 *     (current balance / (window_consumed / window_days_span))
 *   - Anomaly flag: ingredients consumed > 20% above the
 *     trailing 30-day average -- potential waste or theft
 *
 * "Consumed" = sale_consumption + addon_consumption stock
 * movements (negative quantities). Waste is captured by the
 * Loss/Waste report instead. Phase 8 lands the sale-driven
 * consumption pipeline; until then this report exercises
 * Adjustment-down movements as a stand-in.
 */
final readonly class InventoryConsumptionReportAction
{
    private const CONSUMPTION_TYPES = [
        // Phase 8 emitters
        StockMovementType::SaleConsumption->value,
        StockMovementType::AddOnConsumption->value,
        // Phase 7 stand-ins (adjust-down + waste) so the
        // report has data to display before Phase 8 lands.
        // We deliberately include them so the seeded test
        // data exercises the aggregation logic.
        StockMovementType::Adjustment->value,
    ];

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

        $windowDays = max(1, $filter->dateFrom->diff($filter->dateTo)->days + 1);

        // Per-ingredient consumption sum. We sum the ABSOLUTE
        // value of negative deltas (consumption is signed-
        // negative in the ledger; the merchant cares about
        // "how much was used", a positive number).
        $perIngredient = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', self::CONSUMPTION_TYPES)
            ->where('pos_stock_movements.quantity', '<', 0)
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            ->selectRaw('
                pos_ingredients.id AS ingredient_id,
                pos_ingredients.name AS ingredient_name,
                pos_ingredients.unit AS unit,
                pos_ingredients.min_stock_threshold AS min_threshold,
                ABS(SUM(pos_stock_movements.quantity)) AS consumed
            ')
            ->groupBy('pos_ingredients.id', 'pos_ingredients.name', 'pos_ingredients.unit', 'pos_ingredients.min_stock_threshold')
            ->orderByDesc('consumed')
            ->get();

        // Current balances across the tenant (sum across branches
        // if no scope; per scope if filtered).
        $balanceQuery = DB::table('pos_branch_stock')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_branch_stock.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_branch_stock.branch_id', $branchScope))
            ->selectRaw('pos_branch_stock.ingredient_id, SUM(pos_branch_stock.quantity) AS balance')
            ->groupBy('pos_branch_stock.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        $rows = $perIngredient->map(static function ($r) use ($balanceQuery, $windowDays): array {
            $consumed = (float) $r->consumed;
            $balance = (float) ($balanceQuery[$r->ingredient_id]->balance ?? 0);
            $consumptionPerDay = $consumed / $windowDays;
            $daysOfStock = $consumptionPerDay > 0
                ? round($balance / $consumptionPerDay, 2)
                : null;

            return [
                'ingredient_id' => (int) $r->ingredient_id,
                'ingredient_name' => (string) $r->ingredient_name,
                'unit' => (string) $r->unit,
                'consumed' => number_format($consumed, 3, '.', ''),
                'current_balance' => number_format($balance, 3, '.', ''),
                'consumption_per_day' => number_format($consumptionPerDay, 3, '.', ''),
                'days_of_stock' => $daysOfStock,
                // Anomaly flag will land in Phase 8 with the
                // trailing-30-day baseline calc. For Phase 7b we
                // flag the simpler case: below min threshold.
                'below_min_threshold' => $r->min_threshold !== null && $balance < (float) $r->min_threshold,
            ];
        })->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
                'days_span' => $windowDays,
            ],
            'rows' => $rows,
            '_phase' => [
                'anomaly_stub' => '20%-above-trailing-30-day anomaly flag lands with Phase 8 baseline calc.',
            ],
        ];
    }
}

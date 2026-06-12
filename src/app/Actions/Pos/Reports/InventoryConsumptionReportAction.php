<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
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
 *
 * Phase A (Additions §2.11) adds the day-end count columns:
 * counted_units = the LAST physical count in the window (what was
 * most recently verified on the shelf), variance_units = the NET
 * variance all window counts wrote (negative = shortfall booked as
 * waste). Ingredients that were counted but had no consumption in
 * the window are appended as zero-consumption rows so a large
 * variance can never hide.
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
            // P-G4 — central-warehouse rows (branch_id NULL) are pool
            // corrections, not consumption at a branch.
            ->whereNotNull('pos_stock_movements.branch_id')
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

        // Trailing baseline: per-ingredient consumption over the 30 days
        // immediately BEFORE the window, for the ">20% above average" anomaly
        // flag (§5.11.3 — potential waste or theft).
        $trailingDays = 30;
        $trailingFrom = Carbon::instance($filter->dateFrom)->subDays($trailingDays);
        $trailing = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', self::CONSUMPTION_TYPES)
            ->where('pos_stock_movements.quantity', '<', 0)
            ->whereNotNull('pos_stock_movements.branch_id')
            ->where('pos_stock_movements.occurred_at', '>=', $trailingFrom)
            ->where('pos_stock_movements.occurred_at', '<', $filter->dateFrom)
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            ->selectRaw('pos_stock_movements.ingredient_id AS ingredient_id, ABS(SUM(pos_stock_movements.quantity)) AS consumed')
            ->groupBy('pos_stock_movements.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // Phase A — day-end count lines in the window, per ingredient.
        // Ordered ASC so the LAST assignment of `counted`/`at` is the most
        // recent count; variance accumulates across every count in window.
        $countLines = DB::table('pos_stock_count_lines')
            ->join('pos_stock_counts', 'pos_stock_counts.id', '=', 'pos_stock_count_lines.stock_count_id')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_count_lines.ingredient_id')
            ->where('pos_stock_counts.company_id', $companyId)
            ->whereBetween('pos_stock_counts.counted_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_counts.branch_id', $branchScope))
            ->orderBy('pos_stock_counts.counted_at')
            ->get([
                'pos_stock_count_lines.ingredient_id',
                'pos_stock_count_lines.counted_units',
                'pos_stock_count_lines.variance_units',
                'pos_stock_counts.counted_at',
                'pos_ingredients.name AS ingredient_name',
                'pos_ingredients.unit AS unit',
            ]);
        /** @var array<int, array{counted: float, variance: float, at: string, name: string, unit: string}> $counts */
        $counts = [];
        foreach ($countLines as $line) {
            $id = (int) $line->ingredient_id;
            $agg = $counts[$id] ?? ['counted' => 0.0, 'variance' => 0.0, 'at' => '', 'name' => '', 'unit' => ''];
            $agg['counted'] = (float) $line->counted_units;
            $agg['at'] = (string) $line->counted_at;
            $agg['variance'] += (float) $line->variance_units;
            $agg['name'] = (string) $line->ingredient_name;
            $agg['unit'] = (string) $line->unit;
            $counts[$id] = $agg;
        }

        $rows = $perIngredient->map(static function ($r) use ($balanceQuery, $trailing, $counts, $windowDays, $trailingDays): array {
            $consumed = (float) $r->consumed;
            $balance = (float) ($balanceQuery[$r->ingredient_id]->balance ?? 0);
            $consumptionPerDay = $consumed / $windowDays;
            $daysOfStock = $consumptionPerDay > 0
                ? round($balance / $consumptionPerDay, 2)
                : null;
            // Anomaly: window rate exceeds the trailing-30-day average rate by
            // more than 20%. No baseline (zero trailing) ⇒ not flagged.
            $trailingPerDay = (float) ($trailing[$r->ingredient_id]->consumed ?? 0) / $trailingDays;
            $anomaly = $trailingPerDay > 0 && $consumptionPerDay > ($trailingPerDay * 1.2);

            $count = $counts[(int) $r->ingredient_id] ?? null;

            return [
                'ingredient_id' => (int) $r->ingredient_id,
                'ingredient_name' => (string) $r->ingredient_name,
                'unit' => (string) $r->unit,
                'consumed' => number_format($consumed, 3, '.', ''),
                'current_balance' => number_format($balance, 3, '.', ''),
                'consumption_per_day' => number_format($consumptionPerDay, 3, '.', ''),
                'days_of_stock' => $daysOfStock,
                'trailing_avg_per_day' => number_format($trailingPerDay, 3, '.', ''),
                'anomaly' => $anomaly,
                'below_min_threshold' => $r->min_threshold !== null && $balance < (float) $r->min_threshold,
                // Phase A (Additions §2.11) — day-end count columns.
                'counted_units' => $count !== null ? number_format($count['counted'], 3, '.', '') : null,
                'variance_units' => $count !== null ? number_format($count['variance'], 3, '.', '') : null,
                'last_counted_at' => $count !== null ? $count['at'] : null,
            ];
        })->all();

        // Phase A — counted-but-unconsumed ingredients still surface (a big
        // variance must never hide just because nothing sold in the window).
        $consumedIds = array_flip(array_column($rows, 'ingredient_id'));
        foreach ($counts as $ingredientId => $count) {
            if (isset($consumedIds[$ingredientId])) {
                continue;
            }
            $balance = (float) ($balanceQuery[$ingredientId]->balance ?? 0);
            $rows[] = [
                'ingredient_id' => $ingredientId,
                'ingredient_name' => $count['name'],
                'unit' => $count['unit'],
                'consumed' => '0.000',
                'current_balance' => number_format($balance, 3, '.', ''),
                'consumption_per_day' => '0.000',
                'days_of_stock' => null,
                'trailing_avg_per_day' => '0.000',
                'anomaly' => false,
                'below_min_threshold' => false,
                'counted_units' => number_format($count['counted'], 3, '.', ''),
                'variance_units' => number_format($count['variance'], 3, '.', ''),
                'last_counted_at' => $count['at'],
            ];
        }

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
                'days_span' => $windowDays,
            ],
            'rows' => $rows,
        ];
    }
}

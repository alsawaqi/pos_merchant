<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Portion Variance Report — where did the food actually go, in money.
 *
 * The Loss/Waste report's "shortfall" section compares theoretical sales
 * usage to total depletion in QUANTITY only, and lumps every non-sale
 * outflow (manual waste, count shortfalls, adjustments, even transfers
 * out) into one number. This report is the honest decomposition, per
 * ingredient, in qty AND cost — cost always from the unit_cost_at_time
 * FROZEN on each event row, never the ingredient's current cost:
 *
 *   theoretical — sale_consumption + addon_consumption movements: what the
 *                 recipes say sales used. The book stock already follows
 *                 this by construction, which is exactly why it can't be
 *                 compared against itself — the truth signal is the counts.
 *   waste       — pos_waste_records EXCLUDING reason=reconciliation_variance:
 *                 losses staff recorded and explained (spoilage, prep, …).
 *   count_variance — pos_stock_count_lines.variance_units: the day-end
 *                 physical count vs the running book balance. Negative =
 *                 stock missing beyond every recorded reason (over-portioning,
 *                 unrecorded waste, theft); positive = overage. THE number
 *                 this report exists for. Count shortfalls also write
 *                 reconciliation_variance waste rows and count overages write
 *                 count-linked adjustment movements — both are excluded from
 *                 the other buckets (reason filter / stock_movement_id link,
 *                 which BOTH the portal and the device submission set), so
 *                 nothing is double-counted.
 *   adjustments — manual adjustment movements NOT linked to a count line:
 *                 corrections someone typed in by hand. Worth eyes: a
 *                 recurring manual minus is its own smell.
 *
 * Logistics movements (restock/received/transfer/allocation) are deliberately
 * NOT usage and never enter any bucket — the Loss/Waste shortfall's
 * transfer-out overstatement is the bug this avoids.
 *
 * A fifth bucket, production — production_consumption net of
 * production_return: cooked products consume ingredients at batch start,
 * not sale time, so production-heavy ingredients would otherwise show zero
 * usage against a very real count variance.
 *
 * Coverage honesty: count_variance only exists where somebody counted.
 * Rows carry last_counted_at (null = variance-blind in this window) and the
 * headline reports how many consumed ingredients went uncounted.
 *
 * Cadence caveat: a count's variance_units reconciles drift accrued since
 * that ingredient's PREVIOUS count, which may predate the window, while
 * variance_pct divides by in-window usage only — the % overstates for
 * short windows containing a count day and understates for windows without
 * one. Read it over count-cycle-sized windows.
 */
final readonly class PortionVarianceReportAction
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

        $saleTypes = [
            StockMovementType::SaleConsumption->value,
            StockMovementType::AddOnConsumption->value,
        ];

        // ---- Theoretical usage (recipes × sales), qty + frozen cost ----
        // SIGNED netting, deliberately NOT `quantity < 0` + ABS: a voided
        // paid order writes POSITIVE reversal rows of these same movement
        // types (pos_api ConsumeInventoryAction::reverse), and dropping them
        // would keep the voided sale as phantom "usage" the count can never
        // match. -SUM nets each void against its consumption. (A void that
        // crosses midnight nets in the later window — a small negative
        // theoretical there is honest netting; the variance_pct guard below
        // already tolerates it.)
        $theoretical = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', $saleTypes)
            // P-G4 — central-warehouse rows (branch NULL) are pool moves.
            ->whereNotNull('pos_stock_movements.branch_id')
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            ->selectRaw('
                pos_stock_movements.ingredient_id,
                -SUM(pos_stock_movements.quantity) AS qty,
                -SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time) AS cost
            ')
            ->groupBy('pos_stock_movements.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // ---- Kitchen-production usage (P-G1), qty + frozen cost ----
        // Cooked products consume their ingredients at BATCH START
        // (production_consumption), not at sale time — without this bucket,
        // production-heavy ingredients would show zero usage while their
        // count variance is real. production_return rows (cancelled batch)
        // are positive; signed netting handles both.
        $production = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', [
                StockMovementType::ProductionConsumption->value,
                StockMovementType::ProductionReturn->value,
            ])
            ->whereNotNull('pos_stock_movements.branch_id')
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            ->selectRaw('
                pos_stock_movements.ingredient_id,
                -SUM(pos_stock_movements.quantity) AS qty,
                -SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time) AS cost
            ')
            ->groupBy('pos_stock_movements.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // ---- Explained waste (manual reasons only) ----
        // The waste-record row is the source (positive qty + its own frozen
        // unit_cost_at_time) — no reference_type matching needed, which
        // sidesteps the merchant-FQCN vs device-table-name split.
        $waste = DB::table('pos_waste_records')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_waste_records.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->where('pos_waste_records.reason', '!=', WasteReason::ReconciliationVariance->value)
            ->whereBetween('pos_waste_records.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_waste_records.branch_id', $branchScope))
            ->selectRaw('
                pos_waste_records.ingredient_id,
                SUM(pos_waste_records.quantity) AS qty,
                SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time) AS cost
            ')
            ->groupBy('pos_waste_records.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // ---- Count variance (the truth signal), signed ----
        $countVariance = DB::table('pos_stock_count_lines')
            ->join('pos_stock_counts', 'pos_stock_counts.id', '=', 'pos_stock_count_lines.stock_count_id')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_count_lines.ingredient_id')
            ->where('pos_stock_counts.company_id', $companyId)
            ->whereBetween('pos_stock_counts.counted_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_counts.branch_id', $branchScope))
            ->selectRaw('
                pos_stock_count_lines.ingredient_id,
                SUM(pos_stock_count_lines.variance_units) AS qty,
                SUM(pos_stock_count_lines.variance_units * pos_stock_count_lines.unit_cost_at_time) AS cost,
                COUNT(*) AS count_events,
                MAX(pos_stock_counts.counted_at) AS last_counted_at
            ')
            ->groupBy('pos_stock_count_lines.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // ---- Manual adjustments (NOT count-driven), signed ----
        $adjustments = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->where('pos_stock_movements.movement_type', StockMovementType::Adjustment->value)
            ->whereNotNull('pos_stock_movements.branch_id')
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            // Count-overage adjustments are the count's, not manual ones —
            // both portal and device submissions link the movement id into
            // the count line.
            ->whereNotIn('pos_stock_movements.id', function ($q): void {
                $q->select('stock_movement_id')
                    ->from('pos_stock_count_lines')
                    ->whereNotNull('stock_movement_id');
            })
            ->selectRaw('
                pos_stock_movements.ingredient_id,
                SUM(pos_stock_movements.quantity) AS qty,
                SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time) AS cost
            ')
            ->groupBy('pos_stock_movements.ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // ---- Assemble: any ingredient touched by any bucket ----
        $ingredientIds = $theoretical->keys()
            ->merge($production->keys())
            ->merge($waste->keys())
            ->merge($countVariance->keys())
            ->merge($adjustments->keys())
            ->unique()
            ->values();

        $ingredients = DB::table('pos_ingredients')
            ->where('company_id', $companyId)
            ->whereIn('id', $ingredientIds)
            ->get(['id', 'name', 'unit'])
            ->keyBy('id');

        $dec = static fn (float $x): string => number_format($x, 3, '.', '');

        $rows = [];
        foreach ($ingredientIds as $id) {
            $ing = $ingredients->get($id);
            if ($ing === null) {
                continue; // cross-tenant guard; joins make this unreachable
            }

            $theoQty = (float) ($theoretical->get($id)->qty ?? 0);
            $theoCost = (float) ($theoretical->get($id)->cost ?? 0);
            $prodQty = (float) ($production->get($id)->qty ?? 0);
            $prodCost = (float) ($production->get($id)->cost ?? 0);
            $wasteQty = (float) ($waste->get($id)->qty ?? 0);
            $wasteCost = (float) ($waste->get($id)->cost ?? 0);
            $cvQty = (float) ($countVariance->get($id)->qty ?? 0);
            $cvCost = (float) ($countVariance->get($id)->cost ?? 0);
            $adjQty = (float) ($adjustments->get($id)->qty ?? 0);
            $adjCost = (float) ($adjustments->get($id)->cost ?? 0);

            // Everything recipes accounted for: sales + kitchen production.
            $usageQty = $theoQty + $prodQty;

            $rows[] = [
                'ingredient_id' => (int) $id,
                'ingredient_name' => (string) $ing->name,
                'unit' => (string) $ing->unit,
                'theoretical_qty' => $dec($theoQty),
                'theoretical_cost' => $dec($theoCost),
                'production_qty' => $dec($prodQty),
                'production_cost' => $dec($prodCost),
                'waste_qty' => $dec($wasteQty),
                'waste_cost' => $dec($wasteCost),
                'count_variance_qty' => $dec($cvQty),
                'count_variance_cost' => $dec($cvCost),
                'adjustment_qty' => $dec($adjQty),
                'adjustment_cost' => $dec($adjCost),
                // Negative = missing stock as % of what recipes used (sales
                // + production). NULL when there was no recipe usage to
                // compare against. Cadence caveat: a count reconciles drift
                // accrued since the PREVIOUS count, which may predate this
                // window — read the % over count-cycle-sized windows.
                'variance_pct' => $usageQty > 0
                    ? number_format($cvQty / $usageQty * 100, 1, '.', '')
                    : null,
                'count_events' => (int) ($countVariance->get($id)->count_events ?? 0),
                'last_counted_at' => $countVariance->get($id)->last_counted_at ?? null,
            ];
        }

        // Worst unexplained loss first (most negative count-variance cost),
        // then biggest explained waste.
        usort($rows, static function (array $a, array $b): int {
            $cmp = (float) $a['count_variance_cost'] <=> (float) $b['count_variance_cost'];

            return $cmp !== 0 ? $cmp : ((float) $b['waste_cost'] <=> (float) $a['waste_cost']);
        });

        // ---- Headline + coverage ----
        $shortfallCost = 0.0;
        $overageCost = 0.0;
        $uncounted = 0;
        foreach ($rows as $row) {
            $cv = (float) $row['count_variance_cost'];
            if ($cv < 0) {
                $shortfallCost += -$cv;
            } else {
                $overageCost += $cv;
            }
            // Variance-blind coverage: ANY recipe usage (sales or kitchen
            // production) with no count in the window.
            $usage = (float) $row['theoretical_qty'] + (float) $row['production_qty'];
            if ($usage > 0 && $row['last_counted_at'] === null) {
                $uncounted++;
            }
        }

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'theoretical_cost' => $dec(array_sum(array_map(static fn ($r) => (float) $r['theoretical_cost'], $rows))),
                'production_cost' => $dec(array_sum(array_map(static fn ($r) => (float) $r['production_cost'], $rows))),
                'waste_cost' => $dec(array_sum(array_map(static fn ($r) => (float) $r['waste_cost'], $rows))),
                'count_shortfall_cost' => $dec($shortfallCost),
                'count_overage_cost' => $dec($overageCost),
                'adjustment_cost' => $dec(array_sum(array_map(static fn ($r) => (float) $r['adjustment_cost'], $rows))),
                // Consumed-but-never-counted ingredients: variance-blind.
                'uncounted_ingredients' => $uncounted,
            ],
            'rows' => $rows,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Loss / Waste Report (blueprint §5.11.5).
 *
 *   - Total waste value in window (by branch, by reason — the
 *     Phase A day-end count's reconciliation_variance reason
 *     shows up here with no extra wiring)
 *   - Top wasted ingredients
 *   - Comparison: theoretical consumption (from sales, i.e. the
 *     sale/addon consumption the recipe snapshots drove) vs total
 *     stock depletion -> shortfall + variance_pct. This IS the
 *     Additions doc's portion-control variance: actual minus
 *     theoretical, per ingredient, as quantity and percent.
 */
final readonly class LossWasteReportAction
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

        // Base: pos_waste_records in the window for the tenant.
        // value = quantity * unit_cost_at_time.
        $wasteBase = DB::table('pos_waste_records')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_waste_records.ingredient_id')
            ->join('pos_branches', 'pos_branches.id', '=', 'pos_waste_records.branch_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereBetween('pos_waste_records.occurred_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $wasteBase->whereIn('pos_waste_records.branch_id', $branchScope);
        }

        // ---- Headline ----
        $headline = (clone $wasteBase)
            ->selectRaw('
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS total_value,
                COALESCE(SUM(pos_waste_records.quantity), 0) AS total_qty,
                COUNT(*) AS event_count
            ')
            ->first();

        // ---- By branch ----
        $byBranch = (clone $wasteBase)
            ->selectRaw('
                pos_waste_records.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value,
                COUNT(*) AS event_count
            ')
            ->groupBy('pos_waste_records.branch_id', 'pos_branches.name')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'branch_name' => (string) $r->branch_name,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

        // ---- By reason ----
        $byReason = (clone $wasteBase)
            ->selectRaw('
                pos_waste_records.reason AS reason,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value,
                COUNT(*) AS event_count
            ')
            ->groupBy('pos_waste_records.reason')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'reason' => (string) $r->reason,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

        // ---- Top wasted ingredients ----
        $topWasted = (clone $wasteBase)
            ->selectRaw('
                pos_ingredients.id AS ingredient_id,
                pos_ingredients.name AS ingredient_name,
                pos_ingredients.unit AS unit,
                COALESCE(SUM(pos_waste_records.quantity), 0) AS total_qty,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value
            ')
            ->groupBy('pos_ingredients.id', 'pos_ingredients.name', 'pos_ingredients.unit')
            ->orderByDesc('value')
            ->limit(10)
            ->get()
            ->map(static fn ($r): array => [
                'ingredient_id' => (int) $r->ingredient_id,
                'ingredient_name' => (string) $r->ingredient_name,
                'unit' => (string) $r->unit,
                'total_qty' => number_format((float) $r->total_qty, 3, '.', ''),
                'value' => number_format((float) $r->value, 3, '.', ''),
            ])->all();

        // ---- Shortfall: actual stock depletion vs theoretical sales usage ----
        // Per ingredient, the stock that left BEYOND what sales recipes account
        // for (recorded waste + manual adjustments). sale_consumption /
        // addon_consumption are the device-derived "theoretical from sales";
        // everything else negative is the shortfall to investigate (§5.11.5).
        $saleList = "'".implode("','", [
            StockMovementType::SaleConsumption->value,
            StockMovementType::AddOnConsumption->value,
        ])."'";
        $shortfall = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->where('pos_stock_movements.quantity', '<', 0)
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_stock_movements.branch_id', $branchScope))
            ->selectRaw("
                pos_ingredients.id AS ingredient_id,
                pos_ingredients.name AS ingredient_name,
                pos_ingredients.unit AS unit,
                ABS(SUM(CASE WHEN pos_stock_movements.movement_type IN ($saleList) THEN pos_stock_movements.quantity ELSE 0 END)) AS sales_consumption,
                ABS(SUM(pos_stock_movements.quantity)) AS total_depletion
            ")
            ->groupBy('pos_ingredients.id', 'pos_ingredients.name', 'pos_ingredients.unit')
            ->get()
            ->map(static function ($r): array {
                $sales = (float) $r->sales_consumption;
                $total = (float) $r->total_depletion;

                return [
                    'ingredient_id' => (int) $r->ingredient_id,
                    'ingredient_name' => (string) $r->ingredient_name,
                    'unit' => (string) $r->unit,
                    'sales_consumption' => number_format($sales, 3, '.', ''),
                    'total_depletion' => number_format($total, 3, '.', ''),
                    'shortfall' => number_format($total - $sales, 3, '.', ''),
                    // Phase A — portion-control variance percent: how far
                    // actual depletion ran over what sales theoretically
                    // used. NULL when there were no sales to compare against.
                    'variance_pct' => $sales > 0
                        ? number_format(($total - $sales) / $sales * 100, 1, '.', '')
                        : null,
                ];
            })
            ->sortByDesc(static fn (array $r): float => (float) $r['shortfall'])
            ->values()
            ->all();

        // ---- Phase B — VOIDS by reason + staff (Additions §1.2: "Voids
        // surface in the Loss/Waste report broken down by reason code and by
        // staff"). Driven by voided pos_orders + the reason label snapshotted
        // at void time; the order value is what the void wrote off.
        $voidBase = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', 'void')
            ->whereBetween('closed_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $voidBase->whereIn('branch_id', $branchScope);
        }
        $voidsByReason = (clone $voidBase)
            ->selectRaw("
                COALESCE(void_reason_label, 'No reason') AS reason,
                COUNT(*) AS void_count,
                COALESCE(SUM(grand_total), 0) AS order_value
            ")
            ->groupBy('void_reason_label')
            ->orderByDesc('order_value')
            ->get()
            ->map(static fn ($r): array => [
                'reason' => (string) $r->reason,
                'void_count' => (int) $r->void_count,
                'order_value' => number_format((float) $r->order_value, 3, '.', ''),
            ])->all();
        $voidsByStaff = DB::table('pos_orders')
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_orders.staff_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', 'void')
            ->whereBetween('pos_orders.closed_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_orders.branch_id', $branchScope))
            ->selectRaw('
                pos_orders.staff_id AS staff_id,
                pos_staff.name AS staff_name,
                COUNT(*) AS void_count,
                COALESCE(SUM(pos_orders.grand_total), 0) AS order_value
            ')
            ->groupBy('pos_orders.staff_id', 'pos_staff.name')
            ->orderByDesc('void_count')
            ->get()
            ->map(static fn ($r): array => [
                'staff_id' => (int) $r->staff_id,
                'staff_name' => (string) $r->staff_name,
                'void_count' => (int) $r->void_count,
                'order_value' => number_format((float) $r->order_value, 3, '.', ''),
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'total_value' => number_format((float) ($headline?->total_value ?? 0), 3, '.', ''),
                'total_qty' => number_format((float) ($headline?->total_qty ?? 0), 3, '.', ''),
                'event_count' => (int) ($headline?->event_count ?? 0),
            ],
            'by_branch' => $byBranch,
            'by_reason' => $byReason,
            'top_wasted' => $topWasted,
            'shortfall' => $shortfall,
            'voids_by_reason' => $voidsByReason,
            'voids_by_staff' => $voidsByStaff,
        ];
    }
}

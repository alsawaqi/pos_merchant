<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Loss / Waste Report (blueprint §5.11.5).
 *
 *   - Total waste value in window (by branch, by reason)
 *   - Top wasted ingredients
 *   - Comparison: theoretical consumption (from sales) vs
 *     actual stock movement -> shortfall
 *     (Phase 8 lands the theoretical-consumption derivation
 *     via order_items.recipe_snapshot_json; for now the
 *     shortfall section is stubbed)
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
            '_phase' => [
                'shortfall_stub' => 'Theoretical-vs-actual shortfall lands with Phase 8 recipe_snapshot_json + sale-consumption pipeline.',
            ],
        ];
    }
}

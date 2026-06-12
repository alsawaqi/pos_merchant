<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Restock / Purchasing Report (blueprint §5.11.6).
 *
 *   - Every Restock stock movement in the window
 *   - Total quantity + total cost (qty * unit_cost_at_time)
 *   - By supplier (via ingredient.primary_supplier_id; "Unassigned"
 *     bucket for ingredients without a primary supplier captured)
 *   - By branch
 *   - Per-ingredient detail rows (top 20 by cost)
 *
 * Restock movements are the SOLE source-of-truth for purchase
 * spend in Phase 5c/7b. Phase 9 might layer a richer invoice/PO
 * model on top, but until then "what did we buy and from whom"
 * is derived from the append-only ledger.
 *
 * P-G4 — central-warehouse receives count too: a 'received' row
 * (branch_id NULL) is a purchase landing in the warehouse, costed
 * at the snapshotted unit cost like a plain restock. It shows as
 * the "Warehouse" bucket in the by-branch breakdown. The later
 * allocation to branches deliberately does NOT count (it would
 * double-bill the same stock). A branch-filtered report keeps
 * excluding warehouse rows — they belong to no branch.
 */
final readonly class RestockPurchasingReportAction
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

        // Base query: restock movements in window for the tenant.
        // Restock quantity is always positive by convention
        // (see StockMovementType enum doc) so SUM is straight.
        // P-G4: leftJoin — central 'received' rows have branch_id NULL.
        // Tenancy anchors on pos_ingredients.company_id, not the branch.
        $restockBase = DB::table('pos_stock_movements')
            ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_stock_movements.ingredient_id')
            ->leftJoin('pos_branches', 'pos_branches.id', '=', 'pos_stock_movements.branch_id')
            ->leftJoin('pos_suppliers', 'pos_suppliers.id', '=', 'pos_ingredients.primary_supplier_id')
            ->where('pos_ingredients.company_id', $companyId)
            ->whereIn('pos_stock_movements.movement_type', [
                StockMovementType::Restock->value,
                StockMovementType::Received->value,
            ])
            ->whereBetween('pos_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $restockBase->whereIn('pos_stock_movements.branch_id', $branchScope);
        }

        // ---- Headline ----
        $headline = (clone $restockBase)
            ->selectRaw('
                COALESCE(SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time), 0) AS total_cost,
                COALESCE(SUM(pos_stock_movements.quantity), 0) AS total_qty,
                COUNT(*) AS event_count
            ')
            ->first();

        // ---- By supplier ----
        // Ingredients with NO primary supplier captured land in
        // a special "Unassigned" bucket so the merchant sees the
        // gap and can fix the ingredient master.
        $bySupplier = (clone $restockBase)
            ->selectRaw('
                pos_ingredients.primary_supplier_id AS supplier_id,
                pos_suppliers.name AS supplier_name,
                COALESCE(SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time), 0) AS cost,
                COUNT(*) AS event_count
            ')
            ->groupBy('pos_ingredients.primary_supplier_id', 'pos_suppliers.name')
            ->orderByDesc('cost')
            ->get()
            ->map(static fn ($r): array => [
                'supplier_id' => $r->supplier_id !== null ? (int) $r->supplier_id : null,
                'supplier_name' => $r->supplier_name !== null
                    ? (string) $r->supplier_name
                    : 'Unassigned',
                'cost' => number_format((float) $r->cost, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

        // ---- By branch ----
        $byBranch = (clone $restockBase)
            ->selectRaw('
                pos_stock_movements.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                COALESCE(SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time), 0) AS cost,
                COUNT(*) AS event_count
            ')
            ->groupBy('pos_stock_movements.branch_id', 'pos_branches.name')
            ->orderByDesc('cost')
            ->get()
            ->map(static fn ($r): array => [
                // P-G4 — NULL = the central warehouse bucket.
                'branch_id' => $r->branch_id !== null ? (int) $r->branch_id : null,
                'branch_name' => $r->branch_name !== null ? (string) $r->branch_name : 'Warehouse',
                'cost' => number_format((float) $r->cost, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

        // ---- Top purchased ingredients (by cost) ----
        $topPurchased = (clone $restockBase)
            ->selectRaw('
                pos_ingredients.id AS ingredient_id,
                pos_ingredients.name AS ingredient_name,
                pos_ingredients.unit AS unit,
                COALESCE(SUM(pos_stock_movements.quantity), 0) AS total_qty,
                COALESCE(SUM(pos_stock_movements.quantity * pos_stock_movements.unit_cost_at_time), 0) AS cost
            ')
            ->groupBy('pos_ingredients.id', 'pos_ingredients.name', 'pos_ingredients.unit')
            ->orderByDesc('cost')
            ->limit(20)
            ->get()
            ->map(static fn ($r): array => [
                'ingredient_id' => (int) $r->ingredient_id,
                'ingredient_name' => (string) $r->ingredient_name,
                'unit' => (string) $r->unit,
                'total_qty' => number_format((float) $r->total_qty, 3, '.', ''),
                'cost' => number_format((float) $r->cost, 3, '.', ''),
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'total_cost' => number_format((float) ($headline?->total_cost ?? 0), 3, '.', ''),
                'total_qty' => number_format((float) ($headline?->total_qty ?? 0), 3, '.', ''),
                'event_count' => (int) ($headline?->event_count ?? 0),
            ],
            'by_supplier' => $bySupplier,
            'by_branch' => $byBranch,
            'top_purchased' => $topPurchased,
            '_phase' => [
                'invoice_stub' => 'Phase 9 invoice/PO model will replace the Restock-movement-derived view with a true purchase ledger including taxes + payment terms.',
            ],
        ];
    }
}

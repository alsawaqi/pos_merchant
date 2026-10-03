<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\StockMovementType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // LAUNCH-P3 fix order 1, K4 — ONE waste event per group: a prep waste
        // writes one record per raw ingredient, all sharing waste_group_uuid;
        // any other record is its own event.
        $events = "COUNT(DISTINCT COALESCE(CAST(pos_waste_records.waste_group_uuid AS TEXT), 'r' || pos_waste_records.id))";

        // ---- Headline ----
        $headline = (clone $wasteBase)
            ->selectRaw("
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS total_value,
                COALESCE(SUM(pos_waste_records.quantity), 0) AS total_qty,
                {$events} AS event_count
            ")
            ->first();

        // ---- By branch ----
        $byBranch = (clone $wasteBase)
            ->selectRaw("
                pos_waste_records.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value,
                {$events} AS event_count
            ")
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
            ->selectRaw("
                pos_waste_records.reason AS reason,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value,
                {$events} AS event_count
            ")
            ->groupBy('pos_waste_records.reason')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'reason' => (string) $r->reason,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

        // ---- LAUNCH-P3 K4 — prep items wasted, by name ----
        // ("1 L of tomato sauce thrown away": one event, valued at the raw
        // ingredients it was made of; those still show in top_wasted.)
        $prepWastes = (clone $wasteBase)
            ->join('pos_ingredients as prep', 'prep.id', '=', 'pos_waste_records.prep_ingredient_id')
            ->where('prep.company_id', $companyId)
            ->selectRaw("
                prep.id AS prep_ingredient_id,
                prep.name AS prep_name,
                prep.unit AS unit,
                COALESCE(SUM(pos_waste_records.quantity * pos_waste_records.unit_cost_at_time), 0) AS value,
                {$events} AS event_count
            ")
            ->groupBy('prep.id', 'prep.name', 'prep.unit')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'prep_ingredient_id' => (int) $r->prep_ingredient_id,
                'prep_name' => (string) $r->prep_name,
                'unit' => (string) $r->unit,
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
            // LAUNCH-P2 P2-6 — a count correction (a late pre-count sale
            // folded into its count) nets against the count's shortfall, so
            // it counts whichever its sign.
            ->where(fn ($q) => $q->where('pos_stock_movements.quantity', '<', 0)
                ->orWhere('pos_stock_movements.movement_type', StockMovementType::CountCorrection->value))
            // P-G4 — branch operations only: central-warehouse rows
            // (branch_id NULL, e.g. allocation_out / a central adjust-down)
            // are pool moves, not branch depletion.
            ->whereNotNull('pos_stock_movements.branch_id')
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

        // ---- Cooked/unit PRODUCT dispositions: ad-hoc + day-end waste and
        // manager-approved give-aways from the product-unit ledger. Kept as its
        // own section (pieces, not ingredient quantities). Value is cost-based:
        // the unit_cost FROZEN on the waste movement when present (cost_price, or
        // a cooked item's recipe cost at waste time — the honest loss figure),
        // falling back to the live cost_price for older rows that predate the
        // frozen column. Grouped by product + type + reason so a wasted cooked
        // OR bought-in product reads with its reason like ingredient waste.
        $productDispositions = DB::table('pos_product_stock_movements')
            ->join('pos_products', 'pos_products.id', '=', 'pos_product_stock_movements.product_id')
            ->where('pos_products.company_id', $companyId)
            ->whereIn('pos_product_stock_movements.movement_type', ['waste', 'give_away'])
            ->whereBetween('pos_product_stock_movements.occurred_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('pos_product_stock_movements.branch_id', $branchScope))
            ->selectRaw('
                pos_products.id AS product_id,
                pos_products.name AS product_name,
                pos_product_stock_movements.movement_type AS movement_type,
                pos_product_stock_movements.reason AS reason,
                ABS(COALESCE(SUM(pos_product_stock_movements.quantity), 0)) AS total_qty,
                ABS(COALESCE(SUM(pos_product_stock_movements.quantity * COALESCE(pos_product_stock_movements.unit_cost, pos_products.cost_price, 0)), 0)) AS value,
                COUNT(*) AS event_count
            ')
            ->groupBy('pos_products.id', 'pos_products.name', 'pos_product_stock_movements.movement_type', 'pos_product_stock_movements.reason')
            ->orderByDesc('total_qty')
            ->get()
            ->map(static fn ($r): array => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'movement_type' => (string) $r->movement_type,
                'reason' => $r->reason !== null ? (string) $r->reason : null,
                'total_qty' => number_format((float) $r->total_qty, 3, '.', ''),
                'value' => number_format((float) $r->value, 3, '.', ''),
                'event_count' => (int) $r->event_count,
            ])->all();

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
            'prep_wastes' => $prepWastes,
            'top_wasted' => $topWasted,
            'shortfall' => $shortfall,
            // P-G1.5 — day-end product waste + give-aways (pieces).
            'product_dispositions' => $productDispositions,
            'table_cancellations' => $this->tableCancellations($companyId, $branchScope, $filter),
            'voids_by_reason' => $voidsByReason,
            'voids_by_staff' => $voidsByStaff,
        ];
    }

    /** A breakdown only: these costs already live in waste/product dispositions. */
    private function tableCancellations(int $companyId, ?array $branchScope, ReportFilter $filter): array
    {
        // The merchant's legacy test/schema mirror intentionally omits the
        // seating journal. Older installations can still read the other sections.
        if (! Schema::hasTable('pos_table_session_events')) {
            return ['rows' => [], 'quantity' => 0, 'cost_baisas' => 0, 'included_in_waste' => true];
        }
        $events = DB::table('pos_table_session_events as e')
            ->leftJoin('pos_tables as t', 't.id', '=', 'e.table_id')
            ->join('pos_branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.company_id', $companyId)->where('b.company_id', $companyId)
            ->where('e.event_type', 'round_resolved')->where('e.payload->action', 'line_cancelled')
            ->whereBetween('e.created_at', [$filter->dateFrom, $filter->dateTo])
            ->when($branchScope !== null, fn ($q) => $q->whereIn('e.branch_id', $branchScope))
            ->select('e.*', 't.label as table_label', 'b.name as branch_name')->orderBy('e.id')->get();
        $rows = [];
        $usedMovements = [];
        foreach ($events as $event) {
            $p = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            $productId = (int) ($p['product_id'] ?? 0);
            $prepared = ($p['prepared'] ?? false) === true;
            $cost = $prepared ? (int) ($p['waste']['cost_baisas'] ?? 0) : 0;
            $matched = true;
            if ($prepared && ! ($p['waste']['booked'] ?? false)) {
                // Older shelf movements have no direct reference id. Only
                // an authoritative successful product.waste ACK may match one;
                // require a unique movement, never guess or count it twice.
                $acks = DB::table('pos_sync_events')->where('device_id', $event->device_id)
                    ->where('event_type', 'product.waste')->where('ack_status', 'processed')
                    ->where('result_json->table_cancellation_waste->request_id', $p['client_request_id'])->orderBy('id')->get();
                $matched = $acks->isNotEmpty();
                foreach ($acks as $ack) {
                    $request = json_decode($ack->payload_json, true, 512, JSON_THROW_ON_ERROR);
                    $proof = json_decode($ack->result_json, true, 512, JSON_THROW_ON_ERROR)['table_cancellation_waste'];
                    if ((int) ($proof['company_id'] ?? 0) !== $companyId || (int) ($proof['branch_id'] ?? 0) !== (int) $event->branch_id) {
                        continue;
                    }
                    $moves = DB::table('pos_product_stock_movements')->where('company_id', $companyId)
                        ->where('branch_id', $event->branch_id)->where('product_id', $productId)->where('movement_type', 'waste')
                        ->where('quantity', -(float) $proof['quantity'])->where('reason', 'other')
                        ->where('recorded_by_pos_staff_id', $request['staff_id'] ?? null)
                        ->where('note', $request['note'] ?? null)
                        ->where('occurred_at', Carbon::parse($request['wasted_at'] ?? $ack->client_timestamp ?? $ack->server_received_at))
                        ->whereNotIn('id', $usedMovements ?: [0])->get();
                    if ($moves->count() !== 1 || $moves->first()->unit_cost === null) {
                        $matched = false;

                        continue;
                    }
                    $movement = $moves->first();
                    $usedMovements[] = (int) $movement->id;
                    $cost += (int) round(abs((float) $movement->quantity) * (float) $movement->unit_cost * 1000);
                }
            }
            $product = DB::table('pos_products')->where('company_id', $companyId)->where('id', $productId)->value('name');
            $addons = DB::table('pos_addons')->where('company_id', $companyId)->whereIn('id', $p['addon_ids'] ?? [])
                ->orderBy('id')->pluck('name')->all();
            $staff = isset($p['staff_id']) ? DB::table('pos_staff')->where('company_id', $companyId)->where('id', $p['staff_id'])->value('name') : null;
            $rows[] = ['id' => (int) $event->id, 'occurred_at' => $event->created_at,
                'branch_id' => (int) $event->branch_id, 'branch_name' => $event->branch_name,
                'table_label' => $event->table_label, 'product_name' => $product, 'addons' => $addons,
                'cancelled_qty' => (int) ($p['cancelled_qty'] ?? 0), 'prepared' => $prepared,
                'cost_baisas' => $cost, 'cost_matched' => $matched, 'staff_name' => $staff,
                'authorized_by' => $p['authorized_by'] ?? null, 'reason' => $p['reason'] ?? null,
                'whole_bill' => (bool) ($p['whole_bill'] ?? false)];
        }

        return ['rows' => $rows, 'quantity' => array_sum(array_column($rows, 'cancelled_qty')),
            'cost_baisas' => array_sum(array_column($rows, 'cost_baisas')), 'included_in_waste' => true];
    }
}

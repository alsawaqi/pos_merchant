<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\RecipeSnapshotCost;
use App\Data\Reports\ReportFilter;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Sales Report (blueprint §5.11.1).
 *
 * Headline metrics + breakdowns derived from pos_orders +
 * pos_payments in the filter window.
 *
 * HEADLINE METRICS:
 *   - gross_sales         Σ paid orders' subtotal (before discount,
 *                          before tax)
 *   - discount_total      Σ paid orders' discount_total
 *   - net_sales           gross_sales - discount_total
 *   - tax_total           Σ paid orders' tax_total
 *   - refunds_total       Σ refunded orders' grand_total
 *   - cogs                Σ line items' recipe_snapshot_json cost
 *                          (Phase 8 fills this; for now
 *                          unit_cost_at_time multiplied by qty)
 *   - gross_profit        net_sales - cogs
 *   - order_count         count of paid + refunded orders
 *
 * Note: blueprint also mentions "expenses" → "net profit". Phase
 * 6 expense feed is deferred to a later phase; net_profit will be
 * computed once that lands.
 *
 * BREAKDOWNS:
 *   - by_hour          [{hour: 0..23, gross, count}]
 *   - by_weekday       [{weekday: 0..6 (Sun=0), gross, count}]
 *   - by_payment_method[{method: cash/card/.., amount, count}]
 *   - by_order_type    [{type: quick/dine_in/.., gross, count}]
 *   - by_branch        [{branch_id, branch_name, gross, count}] (when
 *                       consolidated=false OR multi-branch in scope)
 *
 * Money columns are returned as decimal-3 STRINGS so the JSON layer
 * preserves OMR baisas precision.
 */
final readonly class SalesReportAction
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

        // PAID orders drive most metrics; REFUNDED contribute
        // refunds_total but not gross_sales (they were already
        // counted when paid). We sum within the window the
        // merchant chose; reconciliation of partial-refund
        // edge cases is Phase 8's concern.
        $paidQuery = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $paidQuery->whereIn('branch_id', $branchScope);
        }

        $refundedQuery = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', OrderStatus::Refunded->value)
            ->whereBetween('opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $refundedQuery->whereIn('branch_id', $branchScope);
        }

        // Headline aggregate (one query)
        $headline = (clone $paidQuery)
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) AS gross_sales,
                COALESCE(SUM(discount_total), 0) AS discount_total,
                COALESCE(SUM(tax_total), 0) AS tax_total,
                COALESCE(SUM(grand_total), 0) AS grand_total,
                COUNT(*) AS order_count
            ')
            ->first();

        $refundsRow = (clone $refundedQuery)
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) AS refunds_total,
                COUNT(*) AS refund_count
            ')
            ->first();

        $grossSales = (float) ($headline?->gross_sales ?? 0);
        $discountTotal = (float) ($headline?->discount_total ?? 0);
        $taxTotal = (float) ($headline?->tax_total ?? 0);
        $netSales = $grossSales - $discountTotal;
        $refundsTotal = (float) ($refundsRow?->refunds_total ?? 0);
        // COGS from the recipe + add-on ingredient snapshots the device sale
        // pipeline (pos_api, Phase 8) froze onto each line — immune to later
        // recipe/price edits. gross_profit = net_sales − COGS. (net_profit
        // additionally subtracts expenses; that feed is a separate stub.)
        $cogs = $this->cogs($paidQuery);
        $grossProfit = $netSales - $cogs;

        // Operating expenses for NET profit. Excludes the 'ingredients'
        // category (COGS already recognises ingredient cost when the stock
        // is consumed in a sale -- counting the purchase here too would
        // double-count) and rejected expenses. Scoped to the same window +
        // branch scope as the sales: a branch-filtered report counts that
        // branch's expenses; consolidated counts every branch PLUS the
        // general (no-branch) expenses.
        $expenseQuery = DB::table('pos_expenses')
            ->where('company_id', $companyId)
            ->whereBetween('logged_at', [$filter->dateFrom, $filter->dateTo])
            ->where('status', '!=', ExpenseStatus::Rejected->value)
            ->where('category', '!=', ExpenseCategory::Ingredients->value);
        if ($branchScope !== null) {
            $expenseQuery->whereIn('branch_id', $branchScope);
        }
        $operatingExpenses = (float) $expenseQuery->sum('amount');
        $netProfit = $grossProfit - $operatingExpenses;

        // Breakdowns
        $byHour = $this->byHour($paidQuery);
        $byWeekday = $this->byWeekday($paidQuery);
        $byPaymentMethod = $this->byPaymentMethod($paidQuery);
        $byOrderType = $this->byOrderType($paidQuery);
        $byBranch = $this->byBranch($paidQuery, $branchScope);

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'gross_sales' => self::fmt($grossSales),
                'discount_total' => self::fmt($discountTotal),
                'net_sales' => self::fmt($netSales),
                'tax_total' => self::fmt($taxTotal),
                'refunds_total' => self::fmt($refundsTotal),
                'cogs' => self::fmt($cogs),
                'gross_profit' => self::fmt($grossProfit),
                'operating_expenses' => self::fmt($operatingExpenses),
                'net_profit' => self::fmt($netProfit),
                'order_count' => (int) ($headline?->order_count ?? 0),
                'refund_count' => (int) ($refundsRow?->refund_count ?? 0),
                // Convenience: avg ticket size on paid orders.
                'avg_ticket' => self::fmt(
                    $headline && (int) $headline->order_count > 0
                        ? (float) $headline->grand_total / (int) $headline->order_count
                        : 0.0,
                ),
            ],
            'by_hour' => $byHour,
            'by_weekday' => $byWeekday,
            'by_payment_method' => $byPaymentMethod,
            'by_order_type' => $byOrderType,
            'by_branch' => $byBranch,
        ];
    }

    /**
     * @return list<array{hour: int, gross: string, count: int}>
     */
    private function byHour($paidQuery): array
    {
        // sqlite uses strftime; Postgres uses EXTRACT. Test
        // schema is sqlite — branch on driver.
        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)::int';

        $rows = (clone $paidQuery)
            ->selectRaw("$hourExpr AS hour, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw('hour')
            ->orderByRaw('hour')
            ->get();

        return $rows->map(static fn ($r): array => [
            'hour' => (int) $r->hour,
            'gross' => self::fmt((float) $r->gross),
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * @return list<array{weekday: int, gross: string, count: int}>
     */
    private function byWeekday($paidQuery): array
    {
        $driver = DB::connection()->getDriverName();
        // Sun=0..Sat=6 for both drivers (sqlite strftime('%w'),
        // Postgres EXTRACT DOW).
        $dowExpr = $driver === 'sqlite'
            ? "CAST(strftime('%w', opened_at) AS INTEGER)"
            : 'EXTRACT(DOW FROM opened_at)::int';

        $rows = (clone $paidQuery)
            ->selectRaw("$dowExpr AS weekday, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw('weekday')
            ->orderByRaw('weekday')
            ->get();

        return $rows->map(static fn ($r): array => [
            'weekday' => (int) $r->weekday,
            'gross' => self::fmt((float) $r->gross),
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * @return list<array{method: string, amount: string, count: int}>
     */
    private function byPaymentMethod($paidQuery): array
    {
        // Sum payments on the paid orders, scoped to status=success.
        // NOTE: clone before ->select('id') — $paidQuery is shared across every
        // breakdown; mutating it here would bake a bare `id` into the later
        // byOrderType / byBranch grouped selects (Postgres GROUP BY error).
        $rows = DB::table('pos_payments')
            ->joinSub((clone $paidQuery)->select('id'), 'orders', 'orders.id', '=', 'pos_payments.order_id')
            ->where('pos_payments.status', 'success')
            ->selectRaw('pos_payments.method AS method, COALESCE(SUM(pos_payments.amount), 0) AS amount, COUNT(*) AS cnt')
            ->groupBy('pos_payments.method')
            ->orderBy('pos_payments.method')
            ->get();

        return $rows->map(static fn ($r): array => [
            'method' => (string) $r->method,
            'amount' => self::fmt((float) $r->amount),
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * @return list<array{type: string, gross: string, count: int}>
     */
    private function byOrderType($paidQuery): array
    {
        $rows = (clone $paidQuery)
            ->selectRaw('order_type AS type, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->groupBy('order_type')
            ->orderBy('order_type')
            ->get();

        return $rows->map(static fn ($r): array => [
            'type' => (string) $r->type,
            'gross' => self::fmt((float) $r->gross),
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * @param  list<int>|null  $branchScope
     * @return list<array{branch_id: int, branch_name: string, gross: string, count: int}>
     */
    private function byBranch($paidQuery, ?array $branchScope): array
    {
        // joinSub keeps the paid-order filters intact without making
        // pos_orders.company_id ambiguous once pos_branches is joined.
        $rows = DB::table('pos_branches')
            ->joinSub(
                (clone $paidQuery)->select('branch_id', 'grand_total'),
                'orders',
                'orders.branch_id',
                '=',
                'pos_branches.id',
            )
            ->selectRaw('pos_branches.id AS branch_id, pos_branches.name AS branch_name, COALESCE(SUM(orders.grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->groupBy('pos_branches.id', 'pos_branches.name')
            ->orderBy('pos_branches.id')
            ->get();

        return $rows->map(static fn ($r): array => [
            'branch_id' => (int) $r->branch_id,
            'branch_name' => (string) $r->branch_name,
            'gross' => self::fmt((float) $r->gross),
            'count' => (int) $r->cnt,
        ])->all();
    }

    /**
     * Total COGS (OMR) for the paid orders in scope: the recipe + add-on
     * ingredient cost snapshotted on each line. Read raw via the query
     * builder + summed in PHP (the snapshot is a JSON array, not SQL-summable).
     *
     * @param  Builder  $paidQuery
     */
    private function cogs($paidQuery): float
    {
        $itemRows = DB::table('pos_order_items')
            ->joinSub((clone $paidQuery)->select('id'), 'scoped_orders', 'scoped_orders.id', '=', 'pos_order_items.order_id')
            ->select('pos_order_items.id', 'pos_order_items.qty', 'pos_order_items.recipe_snapshot_json')
            ->get();

        $baisas = 0;
        $qtyByItem = [];
        foreach ($itemRows as $row) {
            $qty = (float) $row->qty;
            $qtyByItem[(int) $row->id] = $qty;
            $baisas += RecipeSnapshotCost::itemBaisas($row->recipe_snapshot_json, $qty);
        }

        if ($qtyByItem !== []) {
            $addonRows = DB::table('pos_order_item_addons')
                ->whereIn('order_item_id', array_keys($qtyByItem))
                ->select('order_item_id', 'ingredient_snapshot_json')
                ->get();
            foreach ($addonRows as $row) {
                $baisas += RecipeSnapshotCost::addonBaisas($row->ingredient_snapshot_json, $qtyByItem[(int) $row->order_item_id] ?? 0.0);
            }
        }

        return $baisas / 1000;
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

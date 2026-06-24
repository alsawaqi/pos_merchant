<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\RecipeSnapshotCost;
use App\Data\Reports\ReportFilter;
use App\Enums\ExpenseStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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
 *   - by_hour_weekday  [{weekday: 0..6 (Sun=0), hour: 0..23, gross, count}]
 *                       sparse — only buckets with paid orders; the
 *                       frontend zero-fills the 7×24 grid for the
 *                       "Sales by Hour" heatmap.
 *   - by_payment_method[{method: cash/card/.., amount, count}]
 *   - by_order_type    [{type: quick/dine_in/.., gross, count}]
 *   - by_offer         [{offer_id, name, amount, count}] — P-F9 offer
 *                       applications (pos_order_discounts rows carrying
 *                       offer_id) grouped by offer, name from the
 *                       rename-safe sale-time snapshot
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

        // PD5 — CASH model. Every non-rejected expense counts the day it was
        // logged, INCLUDING ingredient/stock/physical-item purchases: buying
        // stock IS the expense (the merchant's chosen accounting). COGS below
        // stays computed + shown as an informational recipe-based margin, but
        // is NOT subtracted into net profit — otherwise a recipe user would
        // count ingredient cost twice (the purchase here AND consumption at
        // sale). Scoped to the same window + branch scope as the sales:
        // a branch-filtered report counts that branch's expenses; consolidated
        // counts every branch PLUS central (no-branch HQ) purchases.
        $expenseQuery = DB::table('pos_expenses')
            ->where('company_id', $companyId)
            ->whereBetween('logged_at', [$filter->dateFrom, $filter->dateTo])
            ->where('status', '!=', ExpenseStatus::Rejected->value);
        if ($branchScope !== null) {
            $expenseQuery->whereIn('branch_id', $branchScope);
        }
        $operatingExpenses = (float) $expenseQuery->sum('amount');
        // PT — the tax PAID on purchases in the window (Σ of the tax portion of
        // those same expense amounts). Always surfaced as a tracked figure;
        // whether it's RECOVERABLE (credited back into net profit, like a VAT
        // receivable) is the per-company purchase_tax_recoverable setting. The
        // gross expense total (and its by-category breakdown) is unchanged — the
        // recoverable case just adds the tax back as a profit credit, so the
        // expenses still reconcile.
        $purchaseTaxPaid = (float) $expenseQuery->sum('tax_amount');
        $purchaseTaxRecoverable = $this->purchaseTaxRecoverable($companyId);
        // Commission is folded into net profit (the user's chosen presentation):
        // the admin/bank/other cut is a real cost, so net_profit nets it out
        // alongside expenses. Settled-aware — the bank's ACTUAL fee where the
        // sale is reconciled, the estimate otherwise. The same read also splits
        // the merchant's take into FINALIZED (payout paid, or no-commission cash
        // in hand) vs PENDING (still held until paid out) so the report can show
        // what is realised income vs not.
        $settlement = $this->commissionSettlement($paidQuery, $companyId);
        $commissionTotal = $settlement['commission_total'];
        $netProfit = $netSales - $commissionTotal - $operatingExpenses
            + ($purchaseTaxRecoverable ? $purchaseTaxPaid : 0.0);
        $byExpenseCategory = $this->byExpenseCategory($companyId, $filter, $branchScope);

        // Breakdowns
        $byHour = $this->byHour($paidQuery);
        $byWeekday = $this->byWeekday($paidQuery);
        $byHourWeekday = $this->byHourWeekday($paidQuery);
        $byPaymentMethod = $this->byPaymentMethod($paidQuery);
        $byOrderType = $this->byOrderType($paidQuery);
        $byOffer = $this->byOffer($paidQuery);
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
                // PD5 — COGS + gross_profit are INFORMATIONAL (recipe-based
                // ingredient margin); net_profit uses the cash expenses, not
                // these, so recipe users still see a margin without double-count.
                'cogs' => self::fmt($cogs),
                'gross_profit' => self::fmt($grossProfit),
                'operating_expenses' => self::fmt($operatingExpenses),
                // PT — total tax PAID on purchases in the window (always shown),
                // + whether it was credited back into net profit (the setting).
                'purchase_tax_paid' => self::fmt($purchaseTaxPaid),
                'purchase_tax_recoverable' => $purchaseTaxRecoverable,
                // Commission folded into net_profit (settled-aware). The split
                // lets the merchant see the platform/bank cut + what is realised
                // (finalized) vs still held (pending) income.
                'admin_commission' => self::fmt($settlement['admin_commission']),
                'bank_commission' => self::fmt($settlement['bank_commission']),
                'other_commission' => self::fmt($settlement['other_commission']),
                'commission_total' => self::fmt($commissionTotal),
                'merchant_net' => self::fmt($settlement['merchant_net']),
                'finalized_net' => self::fmt($settlement['finalized_net']),
                'pending_net' => self::fmt($settlement['pending_net']),
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
            'by_hour_weekday' => $byHourWeekday,
            'by_payment_method' => $byPaymentMethod,
            'by_order_type' => $byOrderType,
            'by_offer' => $byOffer,
            'by_branch' => $byBranch,
            'by_expense_category' => $byExpenseCategory,
        ];
    }

    /**
     * PD5 — the expense breakdown that drives net profit: non-rejected
     * pos_expenses in the window/scope grouped by category, biggest first,
     * with the company's display name for each category key.
     *
     * @param  list<int>|null  $branchScope
     * @return list<array{category: string, name: string, name_ar: string|null, amount: string, count: int}>
     */
    private function byExpenseCategory(int $companyId, ReportFilter $filter, ?array $branchScope): array
    {
        $query = DB::table('pos_expenses')
            ->where('company_id', $companyId)
            ->whereBetween('logged_at', [$filter->dateFrom, $filter->dateTo])
            ->where('status', '!=', ExpenseStatus::Rejected->value);
        if ($branchScope !== null) {
            $query->whereIn('branch_id', $branchScope);
        }

        $rows = $query
            ->selectRaw('category, COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS cnt')
            ->groupBy('category')
            ->orderByRaw('SUM(amount) DESC')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Display names from the company's category rows (key -> {name,
        // name_ar}); fall back to a title-cased key for any legacy/un-seeded
        // category. The frontend picks name vs name_ar by the active locale.
        $categories = DB::table('pos_expense_categories')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get(['key', 'name', 'name_ar'])
            ->keyBy('key');

        return $rows->map(static function ($r) use ($categories): array {
            $cat = $categories->get($r->category);

            return [
                'category' => (string) $r->category,
                'name' => (string) ($cat->name ?? ucfirst(str_replace('_', ' ', (string) $r->category))),
                'name_ar' => $cat->name_ar ?? null,
                'amount' => self::fmt((float) $r->amount),
                'count' => (int) $r->cnt,
            ];
        })->all();
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
     * Combined (day-of-week × hour) gross matrix for the "Sales by Hour"
     * heatmap. Sparse: only buckets that have paid orders are returned;
     * the frontend fills the rest of the 7×24 grid with zeros. Driver-aware
     * (sqlite strftime in tests, Postgres EXTRACT in prod).
     *
     * @return list<array{weekday: int, hour: int, gross: string, count: int}>
     */
    private function byHourWeekday($paidQuery): array
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)::int';
        $dowExpr = $driver === 'sqlite'
            ? "CAST(strftime('%w', opened_at) AS INTEGER)"
            : 'EXTRACT(DOW FROM opened_at)::int';

        $rows = (clone $paidQuery)
            ->selectRaw("$dowExpr AS weekday, $hourExpr AS hour, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw("$dowExpr, $hourExpr")
            ->orderByRaw("$dowExpr, $hourExpr")
            ->get();

        return $rows->map(static fn ($r): array => [
            'weekday' => (int) $r->weekday,
            'hour' => (int) $r->hour,
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
     * P-F9 — offer applications on the paid orders in scope: the
     * pos_order_discounts rows carrying offer_id, grouped per offer.
     * The displayed name is the rename-safe sale-time snapshot (MAX in
     * SQL — one stable label per group even across a mid-window rename).
     * Plain discount rows (offer_id NULL) are excluded; same joinSub
     * pattern as byPaymentMethod.
     *
     * @return list<array{offer_id: int, name: string, amount: string, count: int}>
     */
    private function byOffer($paidQuery): array
    {
        $rows = DB::table('pos_order_discounts')
            ->joinSub((clone $paidQuery)->select('id'), 'orders', 'orders.id', '=', 'pos_order_discounts.order_id')
            ->whereNotNull('pos_order_discounts.offer_id')
            ->selectRaw('pos_order_discounts.offer_id AS offer_id, MAX(pos_order_discounts.name_snapshot) AS name, COALESCE(SUM(pos_order_discounts.amount), 0) AS amount, COUNT(*) AS cnt')
            ->groupBy('pos_order_discounts.offer_id')
            ->orderByRaw('SUM(pos_order_discounts.amount) DESC')
            ->get();

        return $rows->map(static fn ($r): array => [
            'offer_id' => (int) $r->offer_id,
            'name' => (string) $r->name,
            'amount' => self::fmt((float) $r->amount),
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
     * Commission settlement totals for the paid orders in scope, from the
     * append-only pos_sale_commissions ledger + the stateful pos_payouts.
     * Settled-aware throughout (COALESCE(settled_amount, commission_amount) —
     * the bank's actual fee where reconciled, the estimate otherwise), mirroring
     * PayoutBreakdownReportAction + the per-sale SaleCommissionStatus.
     *
     *   admin/bank/other_commission, commission_total — the deductions (every
     *     party EXCEPT the merchant residual); commission_total folds into
     *     net_profit.
     *   merchant_net  — the merchant's total take: the residual party where a
     *     sale has a commission profile, PLUS the full grand_total of sales with
     *     NO profile (the merchant keeps 100%; cash already in hand).
     *   finalized_net — the slice of merchant_net that is REALISED: a sale whose
     *     payout is PAID, plus the no-profile cash sales.
     *   pending_net   — merchant_net still HELD until a payout is paid (the
     *     residual of profiled sales not yet in a paid payout).
     *
     * @param  Builder  $paidQuery
     * @return array{admin_commission: float, bank_commission: float, other_commission: float, commission_total: float, merchant_net: float, finalized_net: float, pending_net: float}
     */
    private function commissionSettlement($paidQuery, int $companyId): array
    {
        // Deductions — every party but the merchant residual, grouped.
        $deductRows = DB::table('pos_sale_commissions')
            ->joinSub((clone $paidQuery)->select('id'), 'scoped', 'scoped.id', '=', 'pos_sale_commissions.order_id')
            ->where('pos_sale_commissions.company_id', $companyId)
            ->where('pos_sale_commissions.party_type', '!=', 'merchant')
            ->selectRaw('pos_sale_commissions.party_type AS party, COALESCE(SUM(COALESCE(pos_sale_commissions.settled_amount, pos_sale_commissions.commission_amount)), 0) AS amount')
            ->groupBy('pos_sale_commissions.party_type')
            ->get();

        $admin = 0.0;
        $bank = 0.0;
        $other = 0.0;
        foreach ($deductRows as $r) {
            $amt = (float) $r->amount;
            if ($r->party === 'platform') {
                $admin += $amt;
            } elseif ($r->party === 'bank') {
                $bank += $amt;
            } else {
                $other += $amt; // 'other' + any future non-merchant party
            }
        }

        // Merchant residual, bucketed by whether its payout is PAID.
        $merch = DB::table('pos_sale_commissions')
            ->joinSub((clone $paidQuery)->select('id'), 'scoped', 'scoped.id', '=', 'pos_sale_commissions.order_id')
            ->leftJoin('pos_payouts', 'pos_payouts.id', '=', 'pos_sale_commissions.payout_id')
            ->where('pos_sale_commissions.company_id', $companyId)
            ->where('pos_sale_commissions.party_type', 'merchant')
            ->selectRaw("
                COALESCE(SUM(COALESCE(pos_sale_commissions.settled_amount, pos_sale_commissions.commission_amount)), 0) AS total,
                COALESCE(SUM(CASE WHEN pos_payouts.status = 'paid' THEN COALESCE(pos_sale_commissions.settled_amount, pos_sale_commissions.commission_amount) ELSE 0 END), 0) AS finalized
            ")
            ->first();
        $merchTotal = (float) ($merch?->total ?? 0);
        $merchFinalized = (float) ($merch?->finalized ?? 0);

        // No-commission sales — no rows in the ledger at all. Two cases: a
        // merchant with NO commission profile (keeps 100%), or a FULLY GIFTED
        // order (pos_api records nothing when collected == 0). Either way the
        // merchant keeps only what was COLLECTED, so subtract any gifted
        // (never-collected) portion — else a comped/gifted bill would show up as
        // cash in hand. Mirrors pos_api's collected = grand_total − gift tenders.
        $noneOrders = (clone $paidQuery)
            ->whereNotExists(function ($q) use ($companyId): void {
                $q->select(DB::raw(1))
                    ->from('pos_sale_commissions')
                    ->whereColumn('pos_sale_commissions.order_id', 'pos_orders.id')
                    ->where('pos_sale_commissions.company_id', $companyId);
            });
        $noneGross = (float) (clone $noneOrders)->sum('grand_total');
        $noneGifted = (float) DB::table('pos_payments')
            ->whereIn('order_id', (clone $noneOrders)->select('pos_orders.id'))
            ->where('method', PaymentMethod::Gift->value)
            ->where('status', 'success')
            ->sum('amount');
        $noneTotal = $noneGross - $noneGifted;

        return [
            'admin_commission' => $admin,
            'bank_commission' => $bank,
            'other_commission' => $other,
            'commission_total' => $admin + $bank + $other,
            'merchant_net' => $merchTotal + $noneTotal,
            'finalized_net' => $merchFinalized + $noneTotal,
            'pending_net' => $merchTotal - $merchFinalized,
        ];
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

    /**
     * PT — the per-company purchase_tax_recoverable flag (pos_company_settings,
     * a JSON boolean). Default false = the tracked purchase tax is informational
     * (net profit unchanged). Read via the query builder + decoded defensively
     * across drivers (Postgres jsonb vs sqlite text).
     */
    private function purchaseTaxRecoverable(int $companyId): bool
    {
        $value = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'purchase_tax_recoverable')
            ->value('value');

        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return $decoded === true || $decoded === 1 || $decoded === '1' || $decoded === 'true';
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

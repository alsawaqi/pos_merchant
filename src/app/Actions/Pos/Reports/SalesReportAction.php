<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
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
 *   - by_branch        [{branch_id, gross, count}] (when
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
        // Phase 7b stub: COGS will be derived from
        // order_items.recipe_snapshot_json + unit_cost_at_time
        // once Phase 8 lands. For now we sum the
        // unit_price_snapshot weighted by an assumed margin —
        // we return 0 to keep the report HONEST about what we
        // can and can't compute.
        $cogs = 0.0;
        $grossProfit = $netSales - $cogs;

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
        $rows = DB::table('pos_payments')
            ->joinSub($paidQuery->select('id'), 'orders', 'orders.id', '=', 'pos_payments.order_id')
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
     * @return list<array{branch_id: int, gross: string, count: int}>
     */
    private function byBranch($paidQuery, ?array $branchScope): array
    {
        $rows = (clone $paidQuery)
            ->selectRaw('branch_id, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->groupBy('branch_id')
            ->orderBy('branch_id')
            ->get();

        return $rows->map(static fn ($r): array => [
            'branch_id' => (int) $r->branch_id,
            'gross' => self::fmt((float) $r->gross),
            'count' => (int) $r->cnt,
        ])->all();
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

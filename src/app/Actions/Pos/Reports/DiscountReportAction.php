<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Discount Report (blueprint §5.11.7).
 *
 *   - Total discount value granted in the window
 *   - Broken down by: rule (Phase 6d Discount.id), branch,
 *     staff who applied it
 *   - Discount % of gross sales (over-discounting watchdog)
 *
 * Headline + by_branch + by_staff come from pos_orders
 * (discount_total + staff_id). The by_RULE breakdown is
 * driven by pos_order_discounts — the discount-application
 * record the pos_api sale pipeline writes at order.create
 * (Phase 8.10) — joined to pos_orders for the paid/window/
 * branch scope and grouped by rule, with the rule name
 * snapshotted so renamed/soft-deleted rules still read.
 */
final readonly class DiscountReportAction
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

        $paidQuery = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $paidQuery->whereIn('branch_id', $branchScope);
        }

        // ---- Headline ----
        $headline = (clone $paidQuery)
            ->selectRaw('
                COALESCE(SUM(discount_total), 0) AS total_discount,
                COALESCE(SUM(subtotal), 0) AS gross_sales,
                COUNT(*) AS order_count,
                SUM(CASE WHEN discount_total > 0 THEN 1 ELSE 0 END) AS discounted_order_count
            ')
            ->first();

        $totalDiscount = (float) ($headline?->total_discount ?? 0);
        $grossSales = (float) ($headline?->gross_sales ?? 0);
        $discountPercent = $grossSales > 0
            ? round(($totalDiscount / $grossSales) * 100, 2)
            : 0.0;

        // ---- By branch ----
        $byBranch = (clone $paidQuery)
            ->selectRaw('
                branch_id,
                COALESCE(SUM(discount_total), 0) AS total_discount,
                COALESCE(SUM(subtotal), 0) AS gross_sales,
                SUM(CASE WHEN discount_total > 0 THEN 1 ELSE 0 END) AS discounted_order_count
            ')
            ->groupBy('branch_id')
            ->orderByDesc('total_discount')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'total_discount' => number_format((float) $r->total_discount, 3, '.', ''),
                'gross_sales' => number_format((float) $r->gross_sales, 3, '.', ''),
                'discounted_order_count' => (int) $r->discounted_order_count,
                'discount_pct' => (float) $r->gross_sales > 0
                    ? round(((float) $r->total_discount / (float) $r->gross_sales) * 100, 2)
                    : 0.0,
            ])->all();

        // ---- By staff (who rang the discounted orders) ----
        // Built fresh (not cloned) because joining pos_staff would make the
        // base query's unqualified company_id ambiguous. Driven by
        // pos_orders.discount_total + staff_id — no rule attribution needed.
        $byStaffQuery = DB::table('pos_orders')
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_orders.staff_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo])
            ->whereNotNull('pos_orders.staff_id');
        if ($branchScope !== null) {
            $byStaffQuery->whereIn('pos_orders.branch_id', $branchScope);
        }
        $byStaff = $byStaffQuery
            ->selectRaw('
                pos_orders.staff_id AS staff_id,
                pos_staff.name AS staff_name,
                COALESCE(SUM(pos_orders.discount_total), 0) AS total_discount,
                SUM(CASE WHEN pos_orders.discount_total > 0 THEN 1 ELSE 0 END) AS discounted_order_count
            ')
            ->groupBy('pos_orders.staff_id', 'pos_staff.name')
            ->orderByDesc('total_discount')
            ->get()
            ->map(static fn ($r): array => [
                'staff_id' => (int) $r->staff_id,
                'staff_name' => (string) $r->staff_name,
                'total_discount' => number_format((float) $r->total_discount, 3, '.', ''),
                'discounted_order_count' => (int) $r->discounted_order_count,
            ])->all();

        // ---- By rule (which discount rule granted how much) ----
        // Driven by pos_order_discounts — the discount-application record the
        // pos_api sale pipeline writes at order.create (§9.1.6 snapshot).
        // Joined to pos_orders for the paid-status + window + branch scope.
        // Grouped by (discount_id, name_snapshot): a renamed rule reads under
        // the name in force at sale time, and manual (rule-less) discounts
        // group by the name that was entered.
        $byRuleQuery = DB::table('pos_order_discounts as od')
            ->join('pos_orders', 'pos_orders.id', '=', 'od.order_id')
            ->where('od.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $byRuleQuery->whereIn('pos_orders.branch_id', $branchScope);
        }
        $byRule = $byRuleQuery
            ->selectRaw('
                od.discount_id AS discount_id,
                od.name_snapshot AS rule_name,
                COALESCE(SUM(od.amount), 0) AS total_discount,
                COUNT(DISTINCT od.order_id) AS order_count,
                COUNT(*) AS application_count
            ')
            ->groupBy('od.discount_id', 'od.name_snapshot')
            ->orderByDesc('total_discount')
            ->get()
            ->map(static fn ($r): array => [
                'discount_id' => $r->discount_id !== null ? (int) $r->discount_id : null,
                'rule_name' => (string) $r->rule_name,
                'total_discount' => number_format((float) $r->total_discount, 3, '.', ''),
                'order_count' => (int) $r->order_count,
                'application_count' => (int) $r->application_count,
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'total_discount' => number_format($totalDiscount, 3, '.', ''),
                'gross_sales' => number_format($grossSales, 3, '.', ''),
                'discount_pct_of_gross' => $discountPercent,
                'order_count' => (int) ($headline?->order_count ?? 0),
                'discounted_order_count' => (int) ($headline?->discounted_order_count ?? 0),
            ],
            'by_branch' => $byBranch,
            'by_staff' => $byStaff,
            'by_rule' => $byRule,
        ];
    }
}

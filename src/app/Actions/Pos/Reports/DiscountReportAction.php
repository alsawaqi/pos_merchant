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
 * Phase 7b ships an AGGREGATE-level report using
 * pos_orders.discount_total. Per-rule and per-staff
 * breakdowns require a discount_application snapshot
 * column on order_items (or a dedicated audit join), which
 * lands with the Phase 8 sale pipeline. For now the rule +
 * staff breakdowns return empty arrays with a documented
 * stub note.
 *
 * by_branch breakdown ships in full.
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
            // Per-RULE breakdown still needs a discount-application record
            // (which discount rule gave which amount), written at order.create
            // by the pos_api sale pipeline — not yet emitted. by_staff above
            // needs only order.staff_id, so it ships in full.
            'by_rule' => [],
            '_phase' => [
                'by_rule_stub' => 'Per-rule breakdown needs a discount-application record written at order.create (pos_api).',
            ],
        ];
    }
}

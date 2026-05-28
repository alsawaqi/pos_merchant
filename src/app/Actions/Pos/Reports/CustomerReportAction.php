<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Enums\PointLedgerEntryType;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7b — Customer Report (blueprint §5.11.8).
 *
 *   - Top customers by spend (in the window)
 *   - Customer cohort: new vs returning split
 *   - Loyalty: points issued, redeemed, outstanding liability
 *
 * "New" = customer's FIRST order falls within the window.
 * "Returning" = customer ordered before the window started.
 *
 * Loyalty totals scoped to the same window via the Phase 6b
 * point ledger:
 *   - points issued = SUM(points_delta WHERE type=earn or
 *                          type=adjustment positive)
 *   - points redeemed = ABS(SUM(points_delta WHERE
 *                           type=redeem or type=adjustment
 *                           negative))
 *   - outstanding liability = SUM(customers.points_balance)
 *     across the actor's company (snapshot, not window-scoped)
 */
final readonly class CustomerReportAction
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

        // Base: paid orders in the window for this company.
        // Columns qualified because the join into pos_customers
        // (which also has company_id) would trip an ambiguity
        // error otherwise.
        $ordersInWindow = DB::table('pos_orders')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereNotNull('pos_orders.customer_id')
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $ordersInWindow->whereIn('pos_orders.branch_id', $branchScope);
        }

        // ---- Top customers by spend ----
        $topCustomers = (clone $ordersInWindow)
            ->join('pos_customers', 'pos_customers.id', '=', 'pos_orders.customer_id')
            ->selectRaw('
                pos_customers.id AS customer_id,
                pos_customers.name AS customer_name,
                pos_customers.phone AS customer_phone,
                COALESCE(SUM(pos_orders.grand_total), 0) AS total_spend,
                COUNT(*) AS order_count
            ')
            ->groupBy('pos_customers.id', 'pos_customers.name', 'pos_customers.phone')
            ->orderByDesc('total_spend')
            ->limit(20)
            ->get();

        // ---- Cohort: new vs returning ----
        // For each customer in the window, look at their min(opened_at).
        // If min(opened_at) >= dateFrom AND min(opened_at) is in the
        // window, they're "new" in this window. Otherwise "returning".
        $firstOrderTimes = DB::table('pos_orders')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereNotNull('pos_orders.customer_id')
            ->groupBy('pos_orders.customer_id')
            ->selectRaw('pos_orders.customer_id, MIN(pos_orders.opened_at) AS first_order_at')
            ->get()
            ->keyBy('customer_id');

        $newCount = 0;
        $returningCount = 0;
        $customersInWindow = (clone $ordersInWindow)
            ->distinct()
            ->pluck('pos_orders.customer_id');
        foreach ($customersInWindow as $cid) {
            $firstAt = $firstOrderTimes[$cid]->first_order_at ?? null;
            if ($firstAt === null) {
                continue;
            }
            // String compare works for ISO timestamps; Carbon
            // for safety.
            $first = \Illuminate\Support\Carbon::parse($firstAt);
            if ($first >= $filter->dateFrom && $first <= $filter->dateTo) {
                $newCount++;
            } else {
                $returningCount++;
            }
        }

        // ---- Loyalty totals scoped to window ----
        // Phase 6b's point ledger.
        $pointsIssuedQuery = DB::table('pos_customer_point_ledger')
            ->where('company_id', $companyId)
            ->where('points_delta', '>', 0)
            ->whereBetween('occurred_at', [$filter->dateFrom, $filter->dateTo]);
        $pointsRedeemedQuery = DB::table('pos_customer_point_ledger')
            ->where('company_id', $companyId)
            ->where('points_delta', '<', 0)
            ->whereBetween('occurred_at', [$filter->dateFrom, $filter->dateTo]);

        $pointsIssued = (int) ($pointsIssuedQuery->sum('points_delta') ?? 0);
        // points_delta is negative for redeems; abs() flips it
        // for a positive display value.
        $pointsRedeemed = (int) abs((int) ($pointsRedeemedQuery->sum('points_delta') ?? 0));

        // Outstanding liability: total points balance across
        // all customers in the tenant. Snapshot, not window-
        // scoped (the merchant cares about TODAY's liability).
        $outstandingPoints = (int) DB::table('pos_customers')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->sum('points_balance');

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'top_customers' => $topCustomers->map(static fn ($r): array => [
                'customer_id' => (int) $r->customer_id,
                'customer_name' => (string) $r->customer_name,
                'customer_phone' => (string) $r->customer_phone,
                'total_spend' => number_format((float) $r->total_spend, 3, '.', ''),
                'order_count' => (int) $r->order_count,
            ])->all(),
            'cohort' => [
                'new_count' => $newCount,
                'returning_count' => $returningCount,
                'total_count' => $newCount + $returningCount,
            ],
            'loyalty' => [
                'points_issued' => $pointsIssued,
                'points_redeemed' => $pointsRedeemed,
                'net_change' => $pointsIssued - $pointsRedeemed,
                'outstanding_liability' => $outstandingPoints,
            ],
        ];
    }
}

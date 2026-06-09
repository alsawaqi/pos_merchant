<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer 360 analytics (v2 #8) — the aggregates that don't yet exist
 * as endpoints. Tenant-scoped; expects the already-resolved customer id.
 *
 *   - rollups      : lifetime spend, paid-order count, avg ticket,
 *                    first/last order timestamps (PAID orders only)
 *   - favorite_item: the customer's most-ordered product (by qty,
 *                    tiebreak revenue) across their paid orders
 *   - spend_trend  : trailing-12-month paid gross + count, zero-filled,
 *                    driver-aware month bucket (sqlite vs Postgres)
 *
 * Money columns are decimal-3 OMR STRINGS. Guest orders (customer_id
 * NULL) are excluded by the customer_id predicate.
 */
final readonly class CustomerAnalyticsAction
{
    private const TREND_MONTHS = 12;

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $customerId): array
    {
        $companyId = $this->tenant->requiredId();

        $roll = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', OrderStatus::Paid->value)
            ->selectRaw('
                COUNT(*) AS order_count,
                COALESCE(SUM(grand_total), 0) AS total_spend,
                MIN(opened_at) AS first_order_at,
                MAX(opened_at) AS last_order_at
            ')
            ->first();

        $orderCount = (int) ($roll?->order_count ?? 0);
        $totalSpend = (float) ($roll?->total_spend ?? 0);

        return [
            'rollups' => [
                'order_count' => $orderCount,
                'total_spend' => self::fmt($totalSpend),
                'avg_ticket' => self::fmt($orderCount > 0 ? $totalSpend / $orderCount : 0.0),
                'first_order_at' => self::iso($roll?->first_order_at),
                'last_order_at' => self::iso($roll?->last_order_at),
            ],
            'favorite_item' => $this->favoriteItem($companyId, $customerId),
            'spend_trend' => $this->spendTrend($companyId, $customerId, self::TREND_MONTHS),
        ];
    }

    /**
     * Most-ordered product across the customer's paid orders. Ranked by
     * total quantity, tiebroken by revenue. NULL if no paid orders.
     *
     * @return array{product_id: int|null, product_name: string, total_qty: string, total_revenue: string, line_count: int}|null
     */
    private function favoriteItem(int $companyId, int $customerId): ?array
    {
        $row = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.company_id', $companyId)
            ->where('pos_orders.customer_id', $customerId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->selectRaw('
                pos_order_items.product_id AS product_id,
                pos_order_items.product_name_snapshot AS product_name,
                COALESCE(SUM(pos_order_items.qty), 0) AS total_qty,
                COALESCE(SUM(pos_order_items.line_total), 0) AS total_revenue,
                COUNT(*) AS line_count
            ')
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name_snapshot')
            ->orderByDesc('total_qty')
            ->orderByDesc('total_revenue')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_name' => (string) $row->product_name,
            'total_qty' => self::fmt((float) $row->total_qty),
            'total_revenue' => self::fmt((float) $row->total_revenue),
            'line_count' => (int) $row->line_count,
        ];
    }

    /**
     * Trailing-N-month paid gross + count, zero-filled for a continuous
     * chart. Month bucket expression is driver-aware (sqlite tests / pg).
     *
     * @return list<array{month: string, gross: string, count: int}>
     */
    private function spendTrend(int $companyId, int $customerId, int $months): array
    {
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', opened_at)"
            : "to_char(opened_at, 'YYYY-MM')";

        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('status', OrderStatus::Paid->value)
            ->where('opened_at', '>=', $start)
            ->selectRaw("$monthExpr AS ym, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw($monthExpr)
            ->get()
            ->keyBy('ym');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i)->format('Y-m');
            $r = $rows->get($m);
            $series[] = [
                'month' => $m,
                'gross' => self::fmt((float) ($r->gross ?? 0)),
                'count' => (int) ($r->cnt ?? 0),
            ];
        }

        return $series;
    }

    private static function iso(mixed $ts): ?string
    {
        return $ts !== null ? Carbon::parse($ts)->format('Y-m-d\TH:i:s') : null;
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

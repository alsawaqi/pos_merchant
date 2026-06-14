<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Discounted & Comped Products — "which exact product was discounted or
 * comped, and how".
 *
 * The existing Discount/Comp reports answer "how much did each rule/reason
 * give away"; this one pivots to the PRODUCT: for every price reduction on a
 * line, which item, how many, by what TYPE, and the money taken off — unifying
 * all mechanisms in one view.
 *
 * Two source tables, both carrying a nullable order_item_id that links an
 * application to one cart line (NULL = applied to the whole order):
 *   - pos_order_discounts → type 'offer' (offer_id set) or 'discount'
 *     (everything else: a catalogue/manual discount AND a loyalty redemption,
 *     which lands as an ordinary discount row with no distinct marker).
 *   - pos_order_comps → type 'gift' (is_gift) or 'comp' (a manager comp).
 *
 * The product name is the LINE snapshot (pos_order_items.product_name_snapshot)
 * so a renamed or deleted product still reads. Money comes ONLY from the
 * application rows' `amount` (never pos_order_items.line_discount, which is the
 * same money in a second place) so nothing double-counts. Line-attributed rows
 * feed the per-product breakdown; whole-order rows (order_item_id NULL) go to a
 * separate, honest bucket so the grand total still reconciles to the Discount +
 * Comp report headlines. Scope mirrors every report: paid orders, the
 * ReportFilter date window, branch scope, company.
 */
final readonly class DiscountedCompedProductsReportAction
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

        // The four line-attributed contributions (product-resolvable), each a
        // grouped-by-product query tagged with its fixed type.
        $perProduct = [];
        foreach ([
            ['pos_order_discounts', 'od', 'offer', static fn (Builder $q): Builder => $q->whereNotNull('od.offer_id')],
            ['pos_order_discounts', 'od', 'discount', static fn (Builder $q): Builder => $q->whereNull('od.offer_id')],
            ['pos_order_comps', 'oc', 'comp', static fn (Builder $q): Builder => $q->where('oc.is_gift', false)],
            ['pos_order_comps', 'oc', 'gift', static fn (Builder $q): Builder => $q->where('oc.is_gift', true)],
        ] as [$table, $alias, $type, $typeFilter]) {
            foreach ($this->lineRows($table, $alias, $typeFilter, $companyId, $filter) as $r) {
                $perProduct[] = [
                    'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                    'product_name' => (string) $r->product_name,
                    'type' => $type,
                    'units' => number_format((float) $r->units, 3, '.', ''),
                    'total_off' => number_format((float) $r->total_off, 3, '.', ''),
                    'times' => (int) $r->times,
                ];
            }
        }
        // Biggest give-away first.
        usort($perProduct, static fn (array $a, array $b): int => (float) $b['total_off'] <=> (float) $a['total_off']);

        // by_product — collapse the per-(product,type) rows to one row per product.
        $byProductMap = [];
        foreach ($perProduct as $row) {
            $key = $row['product_id'] !== null ? 'p'.$row['product_id'] : 'n'.$row['product_name'];
            $byProductMap[$key] ??= [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'units' => 0.0,
                'total_off' => 0.0,
                'times' => 0,
            ];
            $byProductMap[$key]['units'] += (float) $row['units'];
            $byProductMap[$key]['total_off'] += (float) $row['total_off'];
            $byProductMap[$key]['times'] += $row['times'];
        }
        $byProduct = array_values(array_map(static fn (array $p): array => [
            'product_id' => $p['product_id'],
            'product_name' => $p['product_name'],
            'units' => number_format($p['units'], 3, '.', ''),
            'total_off' => number_format($p['total_off'], 3, '.', ''),
            'times' => $p['times'],
        ], $byProductMap));
        usort($byProduct, static fn (array $a, array $b): int => (float) $b['total_off'] <=> (float) $a['total_off']);

        // Whole-order applications (order_item_id NULL) — not product-specific.
        $orderLevel = [];
        $orderLevelTotal = 0.0;
        foreach ([
            ['pos_order_discounts', 'od', 'offer', static fn (Builder $q): Builder => $q->whereNotNull('od.offer_id')],
            ['pos_order_discounts', 'od', 'discount', static fn (Builder $q): Builder => $q->whereNull('od.offer_id')],
            ['pos_order_comps', 'oc', 'comp', static fn (Builder $q): Builder => $q->where('oc.is_gift', false)],
            ['pos_order_comps', 'oc', 'gift', static fn (Builder $q): Builder => $q->where('oc.is_gift', true)],
        ] as [$table, $alias, $type, $typeFilter]) {
            $row = $this->orderLevelRow($table, $alias, $typeFilter, $companyId, $filter);
            $value = (float) ($row?->total_off ?? 0);
            $times = (int) ($row?->times ?? 0);
            if ($times > 0) {
                $orderLevel[] = ['type' => $type, 'total_off' => number_format($value, 3, '.', ''), 'times' => $times];
                $orderLevelTotal += $value;
            }
        }

        // by_type — every line-attributed row, summed per type, + the order-level
        // share so the type totals reconcile to the grand total.
        $byTypeMap = ['offer' => [0.0, 0], 'discount' => [0.0, 0], 'comp' => [0.0, 0], 'gift' => [0.0, 0]];
        foreach ($perProduct as $row) {
            $byTypeMap[$row['type']][0] += (float) $row['total_off'];
            $byTypeMap[$row['type']][1] += $row['times'];
        }
        foreach ($orderLevel as $row) {
            $byTypeMap[$row['type']][0] += (float) $row['total_off'];
            $byTypeMap[$row['type']][1] += $row['times'];
        }
        $byType = [];
        foreach ($byTypeMap as $type => [$value, $times]) {
            if ($times > 0) {
                $byType[] = ['type' => $type, 'total_off' => number_format($value, 3, '.', ''), 'times' => $times];
            }
        }
        usort($byType, static fn (array $a, array $b): int => (float) $b['total_off'] <=> (float) $a['total_off']);

        $lineTotal = array_sum(array_map(static fn (array $r): float => (float) $r['total_off'], $perProduct));

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $filter->branchScope(),
            ],
            'headline' => [
                'total_taken_off' => number_format($lineTotal + $orderLevelTotal, 3, '.', ''),
                'product_level_total' => number_format($lineTotal, 3, '.', ''),
                'whole_order_total' => number_format($orderLevelTotal, 3, '.', ''),
                'distinct_products' => count($byProduct),
                'application_count' => array_sum(array_map(static fn (array $r): int => $r['times'], $byType)),
            ],
            'by_product' => $byProduct,
            'by_product_and_type' => $perProduct,
            'by_type' => $byType,
            'whole_order' => $orderLevel,
            'recent' => $this->recent($companyId, $filter),
        ];
    }

    /**
     * Line-attributed applications for one source table + type filter, grouped
     * by product (its line-snapshot name survives a rename/delete).
     *
     * @param  Closure(Builder): Builder  $typeFilter
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function lineRows(string $table, string $alias, Closure $typeFilter, int $companyId, ReportFilter $filter): \Illuminate\Support\Collection
    {
        // Aggregate PER LINE first: a line that received two applications of the
        // same type (e.g. an auto + a manual discount) contributes its quantity
        // ONCE — its money still sums per application. MAX(qty) reads the line's
        // single quantity without the join fan-out double-counting it.
        $perLine = $this->scoped($table, $alias, $companyId, $filter)
            ->join('pos_order_items as poi', 'poi.id', '=', "{$alias}.order_item_id")
            ->whereNotNull("{$alias}.order_item_id");
        $typeFilter($perLine);
        $perLine->selectRaw("
                poi.product_id AS product_id,
                poi.product_name_snapshot AS product_name,
                MAX(poi.qty) AS line_units,
                COALESCE(SUM({$alias}.amount), 0) AS line_total_off,
                COUNT(*) AS line_times
            ")
            ->groupBy('poi.id', 'poi.product_id', 'poi.product_name_snapshot');

        return DB::query()->fromSub($perLine, 'lines')
            ->selectRaw('
                lines.product_id AS product_id,
                lines.product_name AS product_name,
                COALESCE(SUM(lines.line_units), 0) AS units,
                COALESCE(SUM(lines.line_total_off), 0) AS total_off,
                COALESCE(SUM(lines.line_times), 0) AS times
            ')
            ->groupBy('lines.product_id', 'lines.product_name')
            ->get();
    }

    /**
     * Whole-order (order_item_id NULL) total for one source + type filter.
     *
     * @param  Closure(Builder): Builder  $typeFilter
     */
    private function orderLevelRow(string $table, string $alias, Closure $typeFilter, int $companyId, ReportFilter $filter): ?object
    {
        $q = $this->scoped($table, $alias, $companyId, $filter)
            ->whereNull("{$alias}.order_item_id");
        $typeFilter($q);

        return $q->selectRaw("COALESCE(SUM({$alias}.amount), 0) AS total_off, COUNT(*) AS times")->first();
    }

    /**
     * Newest 50 individual applications across both sources — a drill-down that
     * shows the exact order, product, type, name/reason, units and amount.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(int $companyId, ReportFilter $filter): array
    {
        $discounts = $this->scoped('pos_order_discounts', 'od', $companyId, $filter)
            ->leftJoin('pos_order_items as poi', 'poi.id', '=', 'od.order_item_id')
            ->selectRaw("
                od.offer_id AS offer_id,
                NULL AS is_gift,
                od.name_snapshot AS name,
                od.amount AS amount,
                poi.product_name_snapshot AS product_name,
                poi.qty AS units,
                od.applied_at AS applied_at,
                pos_orders.uuid AS order_uuid
            ")
            ->orderByDesc('od.applied_at')->orderByDesc('od.id')->limit(50);

        $comps = $this->scoped('pos_order_comps', 'oc', $companyId, $filter)
            ->leftJoin('pos_order_items as poi', 'poi.id', '=', 'oc.order_item_id')
            ->selectRaw("
                NULL AS offer_id,
                oc.is_gift AS is_gift,
                oc.reason_name_snapshot AS name,
                oc.amount AS amount,
                poi.product_name_snapshot AS product_name,
                poi.qty AS units,
                oc.applied_at AS applied_at,
                pos_orders.uuid AS order_uuid
            ")
            ->orderByDesc('oc.applied_at')->orderByDesc('oc.id')->limit(50);

        return $discounts->get()->concat($comps->get())
            ->sortByDesc(fn (object $r): string => (string) ($r->applied_at ?? ''))
            ->take(50)
            ->map(static function (object $r): array {
                $type = $r->is_gift !== null
                    ? ((bool) $r->is_gift ? 'gift' : 'comp')
                    : ($r->offer_id !== null ? 'offer' : 'discount');

                return [
                    'product_name' => $r->product_name !== null ? (string) $r->product_name : null,
                    'type' => $type,
                    'name' => (string) $r->name,
                    'units' => $r->units !== null ? number_format((float) $r->units, 3, '.', '') : null,
                    'amount' => number_format((float) $r->amount, 3, '.', ''),
                    'applied_at' => $r->applied_at !== null ? (string) $r->applied_at : null,
                    'order_uuid' => (string) $r->order_uuid,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Base query for a source table: joined to pos_orders for the paid +
     * window + branch scope, scoped to the company.
     */
    private function scoped(string $table, string $alias, int $companyId, ReportFilter $filter): Builder
    {
        $q = DB::table("{$table} as {$alias}")
            ->join('pos_orders', 'pos_orders.id', '=', "{$alias}.order_id")
            ->where("{$alias}.company_id", $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        $branchScope = $filter->branchScope();
        if ($branchScope !== null) {
            $q->whereIn('pos_orders.branch_id', $branchScope);
        }

        return $q;
    }
}

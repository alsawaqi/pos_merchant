<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dine-in table insights (v2) — the per-table "professional record" the
 * merchant asked for: how many times a table was sat at, the duration of
 * each sitting, how much was spent on it, and which customers sat there.
 *
 * Data already exists: a dine-in sale carries pos_orders.table_id (written
 * live by pos_api), plus opened_at / closed_at (= paid_at) and customer_id.
 * This is a READ-side aggregation over those columns — no new schema.
 *
 *   - A "sitting" = one PAID order on the table (one order == one sitting;
 *     the device may merge carts, but the bill lands on a single table).
 *   - "Duration" = opened_at → closed_at (order-open to payment), the
 *     timestamps we already store. Open / held / kitchen orders mean the
 *     table is occupied RIGHT NOW (active_now) but aren't completed sittings.
 *
 * Two entry points:
 *   - overview(): every table of one branch with its window aggregates
 *     (the Tables overview page; click a row → detail).
 *   - detail(): one table's full record — KPIs, the sittings list, the
 *     customers who sat there, a revenue trend, and a by-hour breakdown.
 *
 * Money is decimal-3 OMR strings ({@see number_format}). Durations are
 * whole seconds. Aggregation is done in PHP (not driver-specific epoch
 * SQL) so the math is identical on sqlite (tests) and Postgres (prod).
 */
final readonly class TableInsightsAction
{
    /** A completed sitting. */
    private const PAID = OrderStatus::Paid;

    /** In-progress statuses = the table is occupied right now. */
    private const ACTIVE_STATUSES = [
        OrderStatus::Open->value,
        OrderStatus::Held->value,
        OrderStatus::Kitchen->value,
    ];

    /** Top customers / busiest buckets cap. */
    private const TOP_N = 8;

    /** Most-recent sittings rendered in the detail list. */
    private const SITTINGS_LIMIT = 100;

    /** Zero-fill the daily trend only for windows this short; else sparse. */
    private const TREND_ZEROFILL_MAX_DAYS = 92;

    /**
     * Every table of one branch with its window aggregates. Tables with zero
     * sittings still appear (all-zero row), so the overview lists the whole
     * floor plan. Caller has already tenant-verified the branch.
     *
     * @return array<string, mixed>
     */
    public function overview(int $companyId, Branch $branch, Carbon $from, Carbon $to): array
    {
        $tables = Table::query()
            ->with('floor:id,name,name_ar,display_order,branch_id')
            ->where('company_id', $companyId)
            ->whereHas('floor', fn ($q) => $q->where('branch_id', $branch->id))
            ->get();

        $tableIds = $tables->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Paid dine-in sittings at this BRANCH in the window — scoped by
        // branch_id, NOT the current table-id list. A table that had sittings
        // and was later soft-deleted keeps its table_id on those orders
        // (DeleteTableAction soft-deletes; the FK never NULLs), so a
        // table-id-list filter would silently drop them and the branch totals
        // would undercount vs the Sales report. The per-table rows below show
        // only CURRENT tables; the branch totals are the full picture (any gap
        // between them = sittings on since-removed tables).
        $paid = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('branch_id', $branch->id)
            ->where('status', self::PAID->value)
            ->whereNotNull('table_id')
            ->whereBetween('opened_at', [$from, $to])
            ->get(['id', 'table_id', 'customer_id', 'grand_total', 'opened_at', 'closed_at']);

        // Joined tables (v2): an order that covered several tables appears under
        // EVERY one of them. Fan each order out across {primary table_id} ∪
        // {pos_order_tables extras}; the branch TOTALS still come from $paid
        // (one row per order) so each sale is counted exactly once.
        $extraByOrder = $paid->isEmpty() ? collect() : DB::table('pos_order_tables')
            ->whereIn('order_id', $paid->pluck('id')->all())
            ->get(['order_id', 'table_id'])
            ->groupBy('order_id');

        $byTable = [];
        foreach ($paid as $o) {
            $targets = [(int) $o->table_id];
            foreach ($extraByOrder->get($o->id, collect()) as $row) {
                if ($row->table_id !== null) {
                    $targets[] = (int) $row->table_id;
                }
            }
            foreach (array_unique($targets) as $tid) {
                $byTable[$tid][] = $o;
            }
        }

        // Tables occupied RIGHT NOW (open / held / kitchen) — a live-state flag,
        // NOT windowed. Includes the primary table_id of any active order PLUS
        // every joined seat of an active order (a live joined party lights up
        // all its tables), matching the per-table detail's occupancy predicate.
        $activeSet = [];
        if ($tableIds !== []) {
            $activeOrders = DB::table('pos_orders')
                ->where('company_id', $companyId)
                ->where('branch_id', $branch->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereNotNull('table_id')
                ->get(['id', 'table_id']);
            foreach ($activeOrders as $o) {
                $activeSet[(int) $o->table_id] = true;
            }
            $activeOrderIds = $activeOrders->pluck('id')->all();
            if ($activeOrderIds !== []) {
                foreach (DB::table('pos_order_tables')->whereIn('order_id', $activeOrderIds)->pluck('table_id') as $tid) {
                    if ($tid !== null) {
                        $activeSet[(int) $tid] = true;
                    }
                }
            }
        }

        $rows = $tables->map(function (Table $t) use ($byTable, $activeSet): array {
            $stats = $this->aggregate(collect($byTable[(int) $t->id] ?? []));

            return [
                'id' => (int) $t->id,
                'uuid' => $t->uuid,
                'label' => (string) $t->label,
                'floor_id' => (int) $t->floor_id,
                'floor_name' => $t->floor?->name,
                'seats' => (int) $t->seats,
                'shape' => $t->shape?->value,
                'status' => $t->status?->value,
                'sittings' => $stats['sittings'],
                'revenue' => $stats['revenue'],
                'avg_spend' => $stats['avg_spend'],
                'avg_duration_seconds' => $stats['avg_duration_seconds'],
                'total_duration_seconds' => $stats['total_duration_seconds'],
                'unique_customers' => $stats['unique_customers'],
                'last_used_at' => $stats['last_used_at'],
                'active_now' => isset($activeSet[(int) $t->id]),
            ];
        })->all();

        $all = $this->aggregate($paid);

        return [
            'branch' => ['id' => (int) $branch->id, 'uuid' => $branch->uuid, 'name' => $branch->name],
            'window' => ['from' => $from->format('Y-m-d\TH:i:s'), 'to' => $to->format('Y-m-d\TH:i:s')],
            'totals' => [
                'table_count' => $tables->count(),
                'sittings' => $all['sittings'],
                'revenue' => $all['revenue'],
                'avg_spend' => $all['avg_spend'],
                'avg_duration_seconds' => $all['avg_duration_seconds'],
                'occupied_now' => collect($rows)->where('active_now', true)->count(),
            ],
            'tables' => $rows,
        ];
    }

    /**
     * One table's full record. Caller has already tenant-verified the table.
     *
     * @return array<string, mixed>
     */
    public function detail(int $companyId, Table $table, Carbon $from, Carbon $to): array
    {
        $table->loadMissing(['floor:id,name,name_ar,branch_id', 'floor.branch:id,name']);

        // Joined tables (v2): this table's record includes every order it
        // covered — whether it was the PRIMARY (pos_orders.table_id) or a
        // JOINED seat (pos_order_tables). One predicate, reused below.
        $coversTable = function ($q) use ($table): void {
            $q->where('table_id', $table->id)
                ->orWhereIn('id', function ($sub) use ($table): void {
                    $sub->select('order_id')
                        ->from('pos_order_tables')
                        ->where('table_id', $table->id);
                });
        };

        // Lightweight set over ALL paid sittings in the window (drives the
        // KPIs, trend, by-hour, top customers).
        $stats = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where($coversTable)
            ->where('status', self::PAID->value)
            ->whereBetween('opened_at', [$from, $to])
            ->get(['id', 'customer_id', 'grand_total', 'opened_at', 'closed_at']);

        $summary = $this->aggregate($stats);
        $summary['first_used_at'] = $this->firstUsedAt($stats);
        [$summary['busiest_hour'], $byHour] = $this->byHour($stats);
        [$summary['busiest_weekday'], $byWeekday] = $this->byWeekday($stats);
        $summary['active_now'] = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where($coversTable)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
        // How many of this table's sittings were JOINED orders (covered >1 table).
        $summary['joined_sittings'] = $stats->isEmpty() ? 0 : DB::table('pos_order_tables')
            ->whereIn('order_id', $stats->pluck('id')->all())
            ->distinct()
            ->count('order_id');

        // The display list (most-recent sittings, capped) — full relations.
        $orders = Order::query()
            ->with(['customer:id,name,phone', 'staff:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId)
            ->where($coversTable)
            ->where('status', self::PAID->value)
            ->whereBetween('opened_at', [$from, $to])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::SITTINGS_LIMIT)
            ->get();

        // Per displayed sitting: the OTHER tables its order covered (primary ∪
        // joined, minus this one) → the "joined with …" labels. Batched (one
        // pivot read + one label read for the page; no N+1).
        $joinedLabelsByOrder = $this->joinedLabelsForSittings($orders, (int) $table->id);

        return [
            'table' => [
                'id' => (int) $table->id,
                'uuid' => $table->uuid,
                'label' => (string) $table->label,
                'seats' => (int) $table->seats,
                'min_party' => $table->min_party !== null ? (int) $table->min_party : null,
                'max_party' => $table->max_party !== null ? (int) $table->max_party : null,
                'shape' => $table->shape?->value,
                'status' => $table->status?->value,
                'floor_id' => (int) $table->floor_id,
                'floor_name' => $table->floor?->name,
                'branch_id' => $table->floor?->branch_id !== null ? (int) $table->floor->branch_id : null,
                'branch_name' => $table->floor?->branch?->name,
            ],
            'window' => ['from' => $from->format('Y-m-d\TH:i:s'), 'to' => $to->format('Y-m-d\TH:i:s')],
            'summary' => $summary,
            'sittings' => $orders->map(fn (Order $o): array => $this->sittingRow($o, $joinedLabelsByOrder[(int) $o->id] ?? []))->all(),
            'top_customers' => $this->topCustomers($stats),
            'revenue_trend' => $this->dailyTrend($stats, $from, $to),
            'by_hour' => $byHour,
            'by_weekday' => $byWeekday,
        ];
    }

    /**
     * Core per-sitting roll-up shared by overview + detail. $orders is a
     * collection of rows carrying grand_total / customer_id / opened_at /
     * closed_at (Eloquent models or stdClass from the query builder).
     *
     * @param  Collection<int, object>  $orders
     * @return array<string, mixed>
     */
    private function aggregate(Collection $orders): array
    {
        $count = $orders->count();
        $revenue = 0.0;
        $durTotal = 0;
        $durCount = 0;
        $customers = [];
        $lastUsedTs = null;

        foreach ($orders as $o) {
            $revenue += (float) $o->grand_total;

            if ($o->customer_id !== null) {
                $customers[(int) $o->customer_id] = true;
            }

            $secs = $this->durationSeconds($o);
            if ($secs !== null) {
                $durTotal += $secs;
                $durCount++;
            }

            if ($o->opened_at !== null) {
                $ts = Carbon::parse($o->opened_at)->getTimestamp();
                if ($lastUsedTs === null || $ts > $lastUsedTs) {
                    $lastUsedTs = $ts;
                }
            }
        }

        return [
            'sittings' => $count,
            'revenue' => number_format($revenue, 3, '.', ''),
            'avg_spend' => number_format($count > 0 ? $revenue / $count : 0, 3, '.', ''),
            'total_duration_seconds' => $durTotal,
            'avg_duration_seconds' => $durCount > 0 ? (int) round($durTotal / $durCount) : 0,
            'unique_customers' => count($customers),
            'last_used_at' => $lastUsedTs !== null ? Carbon::createFromTimestamp($lastUsedTs)->format('Y-m-d\TH:i:s') : null,
        ];
    }

    /** Sitting duration in whole seconds, or null when un-derivable. */
    private function durationSeconds(object $o): ?int
    {
        if ($o->closed_at === null || $o->opened_at === null) {
            return null;
        }
        $secs = Carbon::parse($o->closed_at)->getTimestamp() - Carbon::parse($o->opened_at)->getTimestamp();

        return $secs >= 0 ? $secs : null;
    }

    /** @param  Collection<int, object>  $orders */
    private function firstUsedAt(Collection $orders): ?string
    {
        $min = null;
        foreach ($orders as $o) {
            if ($o->opened_at === null) {
                continue;
            }
            $ts = Carbon::parse($o->opened_at)->getTimestamp();
            if ($min === null || $ts < $min) {
                $min = $ts;
            }
        }

        return $min !== null ? Carbon::createFromTimestamp($min)->format('Y-m-d\TH:i:s') : null;
    }

    /**
     * 24-bucket hour-of-day distribution + the busiest hour.
     *
     * @param  Collection<int, object>  $orders
     * @return array{0: int|null, 1: list<array{hour: int, count: int, gross: string}>}
     */
    private function byHour(Collection $orders): array
    {
        $buckets = array_fill(0, 24, ['count' => 0, 'gross' => 0.0]);
        foreach ($orders as $o) {
            if ($o->opened_at === null) {
                continue;
            }
            $h = (int) Carbon::parse($o->opened_at)->format('G');
            $buckets[$h]['count']++;
            $buckets[$h]['gross'] += (float) $o->grand_total;
        }

        $busiest = null;
        $best = 0;
        $out = [];
        foreach ($buckets as $hour => $b) {
            if ($b['count'] > $best) {
                $best = $b['count'];
                $busiest = $hour;
            }
            $out[] = ['hour' => $hour, 'count' => $b['count'], 'gross' => number_format($b['gross'], 3, '.', '')];
        }

        return [$busiest, $out];
    }

    /**
     * 7-bucket weekday distribution (0 = Sunday, matching Carbon::dayOfWeek
     * and the branch heatmap) + the busiest weekday.
     *
     * @param  Collection<int, object>  $orders
     * @return array{0: int|null, 1: list<array{weekday: int, count: int, gross: string}>}
     */
    private function byWeekday(Collection $orders): array
    {
        $buckets = array_fill(0, 7, ['count' => 0, 'gross' => 0.0]);
        foreach ($orders as $o) {
            if ($o->opened_at === null) {
                continue;
            }
            $w = (int) Carbon::parse($o->opened_at)->dayOfWeek;
            $buckets[$w]['count']++;
            $buckets[$w]['gross'] += (float) $o->grand_total;
        }

        $busiest = null;
        $best = 0;
        $out = [];
        foreach ($buckets as $weekday => $b) {
            if ($b['count'] > $best) {
                $best = $b['count'];
                $busiest = $weekday;
            }
            $out[] = ['weekday' => $weekday, 'count' => $b['count'], 'gross' => number_format($b['gross'], 3, '.', '')];
        }

        return [$busiest, $out];
    }

    /**
     * Top customers who sat at this table (by spend), names resolved.
     *
     * @param  Collection<int, object>  $orders
     * @return list<array{customer_id: int, name: string, phone: string|null, visits: int, spend: string}>
     */
    private function topCustomers(Collection $orders): array
    {
        $byCustomer = [];
        foreach ($orders as $o) {
            if ($o->customer_id === null) {
                continue;
            }
            $cid = (int) $o->customer_id;
            $byCustomer[$cid]['visits'] = ($byCustomer[$cid]['visits'] ?? 0) + 1;
            $byCustomer[$cid]['spend'] = ($byCustomer[$cid]['spend'] ?? 0.0) + (float) $o->grand_total;
        }

        if ($byCustomer === []) {
            return [];
        }

        uasort($byCustomer, static fn ($a, $b): int => $b['spend'] <=> $a['spend']);
        $top = array_slice($byCustomer, 0, self::TOP_N, true);

        $customers = Customer::query()->whereIn('id', array_keys($top))->get()->keyBy('id');

        $out = [];
        foreach ($top as $cid => $agg) {
            $customer = $customers->get($cid);
            $out[] = [
                'customer_id' => $cid,
                'name' => (string) ($customer?->name ?? ('#'.$cid)),
                'phone' => $this->safePhone($customer),
                'visits' => $agg['visits'],
                'spend' => number_format($agg['spend'], 3, '.', ''),
            ];
        }

        return $out;
    }

    /** Phone is encrypted at rest; never let a decrypt hiccup 500 the report. */
    private function safePhone(?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }
        try {
            return $customer->phone;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Zero-filled daily revenue series for short windows, sparse for long
     * ones (a multi-year window mustn't emit thousands of empty days).
     *
     * @param  Collection<int, object>  $orders
     * @return list<array{date: string, gross: string, count: int}>
     */
    private function dailyTrend(Collection $orders, Carbon $from, Carbon $to): array
    {
        $byDay = [];
        foreach ($orders as $o) {
            if ($o->opened_at === null) {
                continue;
            }
            $d = Carbon::parse($o->opened_at)->format('Y-m-d');
            $byDay[$d]['gross'] = ($byDay[$d]['gross'] ?? 0.0) + (float) $o->grand_total;
            $byDay[$d]['count'] = ($byDay[$d]['count'] ?? 0) + 1;
        }

        $span = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        $series = [];
        if ($span <= self::TREND_ZEROFILL_MAX_DAYS) {
            for ($i = 0; $i < $span; $i++) {
                $d = $from->copy()->addDays($i)->format('Y-m-d');
                $series[] = [
                    'date' => $d,
                    'gross' => number_format((float) ($byDay[$d]['gross'] ?? 0), 3, '.', ''),
                    'count' => (int) ($byDay[$d]['count'] ?? 0),
                ];
            }

            return $series;
        }

        ksort($byDay);
        foreach ($byDay as $d => $v) {
            $series[] = [
                'date' => (string) $d,
                'gross' => number_format((float) $v['gross'], 3, '.', ''),
                'count' => (int) $v['count'],
            ];
        }

        return $series;
    }

    /**
     * For each displayed sitting, the labels of the OTHER tables its order
     * covered (primary ∪ joined, minus the table being viewed) — the "joined
     * with …" tag. One pivot query + one label query for the whole page (no
     * N+1). withTrashed so a sitting on a since-removed joined table still
     * resolves a label.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     * @return array<int, list<string>>  order id => other-table labels
     */
    private function joinedLabelsForSittings($orders, int $viewedTableId): array
    {
        $orderIds = $orders->pluck('id')->all();
        if ($orderIds === []) {
            return [];
        }

        $coverByOrder = DB::table('pos_order_tables')
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'table_id'])
            ->groupBy('order_id');

        $otherIdsByOrder = [];
        $allOtherIds = [];
        foreach ($orders as $o) {
            $covered = $o->table_id !== null ? [(int) $o->table_id] : [];
            foreach ($coverByOrder->get($o->id, collect()) as $row) {
                if ($row->table_id !== null) {
                    $covered[] = (int) $row->table_id;
                }
            }
            $others = array_values(array_unique(array_diff($covered, [$viewedTableId])));
            $otherIdsByOrder[(int) $o->id] = $others;
            foreach ($others as $id) {
                $allOtherIds[$id] = true;
            }
        }

        $labels = $allOtherIds === [] ? collect() : Table::withTrashed()
            ->whereIn('id', array_keys($allOtherIds))
            ->pluck('label', 'id');

        $out = [];
        foreach ($otherIdsByOrder as $orderId => $ids) {
            $out[$orderId] = array_values(array_filter(array_map(
                static fn (int $id): ?string => $labels->get($id) !== null ? (string) $labels->get($id) : null,
                $ids,
            )));
        }

        return $out;
    }

    /**
     * @param  list<string>  $joinedTables  other tables this order covered
     * @return array<string, mixed>
     */
    private function sittingRow(Order $o, array $joinedTables = []): array
    {
        $duration = $this->durationSeconds($o);

        return [
            'order_uuid' => $o->uuid,
            'receipt_number' => $o->receipt_number,
            'opened_at' => $o->opened_at?->format('Y-m-d\TH:i:s'),
            'closed_at' => $o->closed_at?->format('Y-m-d\TH:i:s'),
            'duration_seconds' => $duration,
            'grand_total' => (string) $o->grand_total,
            'items_count' => (int) ($o->items_count ?? 0),
            'customer_name' => $o->customer?->name,
            'customer_phone' => $this->safePhone($o->customer),
            'staff_name' => $o->staff?->name,
            // Joined tables (v2) — the OTHER tables this one shared order covered.
            'joined_tables' => $joinedTables,
            'joined' => $joinedTables !== [],
        ];
    }
}

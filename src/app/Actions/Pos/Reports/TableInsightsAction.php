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
            ->get(['table_id', 'customer_id', 'grand_total', 'opened_at', 'closed_at']);

        $byTable = $paid->groupBy('table_id');

        // Currently-occupied tables (open / held / kitchen) — NOT windowed,
        // it's a live-state flag for the overview.
        $activeCounts = $tableIds === [] ? collect() : DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereIn('table_id', $tableIds)
            ->selectRaw('table_id, COUNT(*) AS c')
            ->groupBy('table_id')
            ->pluck('c', 'table_id');

        $rows = $tables->map(function (Table $t) use ($byTable, $activeCounts): array {
            $stats = $this->aggregate($byTable->get($t->id, collect()));

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
                'active_now' => (int) ($activeCounts[$t->id] ?? 0) > 0,
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

        // Lightweight set over ALL paid sittings in the window (drives the
        // KPIs, trend, by-hour, top customers).
        $stats = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('table_id', $table->id)
            ->where('status', self::PAID->value)
            ->whereBetween('opened_at', [$from, $to])
            ->get(['customer_id', 'grand_total', 'opened_at', 'closed_at']);

        $summary = $this->aggregate($stats);
        $summary['first_used_at'] = $this->firstUsedAt($stats);
        [$summary['busiest_hour'], $byHour] = $this->byHour($stats);
        [$summary['busiest_weekday'], $byWeekday] = $this->byWeekday($stats);
        $summary['active_now'] = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->where('table_id', $table->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();

        // The display list (most-recent sittings, capped) — full relations.
        $orders = Order::query()
            ->with(['customer:id,name,phone', 'staff:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId)
            ->where('table_id', $table->id)
            ->where('status', self::PAID->value)
            ->whereBetween('opened_at', [$from, $to])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::SITTINGS_LIMIT)
            ->get();

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
            'sittings' => $orders->map(fn (Order $o): array => $this->sittingRow($o))->all(),
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

    /** @return array<string, mixed> */
    private function sittingRow(Order $o): array
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
        ];
    }
}

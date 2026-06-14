<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Period-over-period sales comparison (dashboard + branch control center).
 *
 * Compares a CURRENT calendar period (this week / this month, or a prior one via
 * `offset`) against the PREVIOUS equivalent period, returning a zero-filled daily
 * gross series for each (to overlay on a line chart) plus a "to-date" % change.
 *
 * The % change is apples-to-apples: when the current period is still in progress
 * it compares the elapsed days against the SAME number of days of the previous
 * period (so a half-finished month doesn't read as "down" just because it isn't
 * over yet). `offset` lets the caller step back to a chosen past period.
 *
 * Branch-scoped: $branchIds NULL = every branch the actor may see; a single id =
 * one branch (the branch-detail page passes its own id). Money = decimal-3 OMR.
 */
final readonly class SalesComparisonAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function handle(?array $branchIds, string $period, int $offset): array
    {
        $companyId = $this->tenant->requiredId();
        $period = $period === 'month' ? 'month' : 'week';
        $offset = max(0, $offset);

        [$curFrom, $curTo] = $this->bounds($period, $offset);
        [$prevFrom, $prevTo] = $this->bounds($period, $offset + 1);

        $curSeries = $this->dailySeries($companyId, $branchIds, $curFrom, $curTo);
        $prevSeries = $this->dailySeries($companyId, $branchIds, $prevFrom, $prevTo);

        $today = Carbon::now()->startOfDay();
        $curLen = count($curSeries);
        $prevLen = count($prevSeries);

        // How many days of the current period have elapsed (to-date fairness).
        $inProgress = $curFrom->copy()->startOfDay()->lessThanOrEqualTo($today)
            && $curTo->copy()->startOfDay()->greaterThanOrEqualTo($today);
        if ($curFrom->copy()->startOfDay()->greaterThan($today)) {
            $elapsed = 0;
        } elseif ($inProgress) {
            $elapsed = (int) $curFrom->copy()->startOfDay()->diffInDays($today) + 1;
        } else {
            $elapsed = $curLen;
        }
        $elapsed = max(0, min($elapsed, $curLen));
        $prevCmp = min($elapsed, $prevLen);

        $curTotal = $this->sum($curSeries);
        $prevToDate = $this->sum(array_slice($prevSeries, 0, $prevCmp));
        $prevFull = $this->sum($prevSeries);

        $changePct = $prevToDate > 0.0
            ? round((($curTotal - $prevToDate) / $prevToDate) * 100, 1)
            : null;

        return [
            'period' => $period,
            'offset' => $offset,
            'in_progress' => $inProgress,
            'change_pct' => $changePct,
            'current' => [
                'from' => $curFrom->format('Y-m-d'),
                'to' => $curTo->format('Y-m-d'),
                'total' => $this->fmt($curTotal),
                'series' => $curSeries,
            ],
            'previous' => [
                'from' => $prevFrom->format('Y-m-d'),
                'to' => $prevTo->format('Y-m-d'),
                // `total` is the comparable to-date figure (drives change_pct);
                // `full_total` is the whole previous period for reference.
                'total' => $this->fmt($prevToDate),
                'full_total' => $this->fmt($prevFull),
                'series' => $prevSeries,
            ],
        ];
    }

    /**
     * Calendar bounds for the period `offset` steps before the current one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $period, int $offset): array
    {
        $now = Carbon::now();

        if ($period === 'month') {
            $from = $now->copy()->startOfMonth()->subMonthsNoOverflow($offset);

            return [$from->copy()->startOfMonth(), $from->copy()->endOfMonth()];
        }

        // Week starts Sunday (matches the POS weekday convention used elsewhere).
        $from = $now->copy()->startOfWeek(Carbon::SUNDAY)->subWeeks($offset);

        return [$from->copy()->startOfDay(), $from->copy()->addDays(6)->endOfDay()];
    }

    /**
     * Zero-filled daily paid-gross series over [from, to]. Driver-aware date
     * expression (sqlite tests vs Postgres prod).
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{i: int, date: string, gross: string}>
     */
    private function dailySeries(int $companyId, ?array $branchIds, Carbon $from, Carbon $to): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : "to_char(opened_at, 'YYYY-MM-DD')";

        $rows = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw("$dayExpr AS day, COALESCE(SUM(grand_total), 0) AS gross")
            ->groupByRaw($dayExpr)
            ->get()
            ->keyBy('day');

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $i = 0;
        while ($cursor->lessThanOrEqualTo($end)) {
            $d = $cursor->format('Y-m-d');
            $r = $rows->get($d);
            $series[] = [
                'i' => $i,
                'date' => $d,
                'gross' => number_format((float) ($r->gross ?? 0), 3, '.', ''),
            ];
            $cursor->addDay();
            $i++;
        }

        return $series;
    }

    /**
     * @param  list<array{gross: string}>  $series
     */
    private function sum(array $series): float
    {
        $total = 0.0;
        foreach ($series as $p) {
            $total += (float) $p['gross'];
        }

        return $total;
    }

    private function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}

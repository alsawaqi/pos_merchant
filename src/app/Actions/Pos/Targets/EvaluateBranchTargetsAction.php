<?php

declare(strict_types=1);

namespace App\Actions\Pos\Targets;

use App\Enums\BranchTargetPeriod;
use App\Enums\OrderStatus;
use App\Models\BranchTarget;
use App\Models\BranchTargetWindow;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * P-G8 — the window engine. Windows run BACK-TO-BACK from the target's
 * starts_on; the goal is CUMULATIVE inside each window (amount x
 * window_periods).
 *
 *   day    window length = N days;
 *   week   7-day blocks from the anchor (N weeks = 7N days) — NOT
 *          calendar weeks, so a Wednesday anchor stays Wednesday-based;
 *   month  calendar months without overflow (a Jan-31 anchor never
 *          skips February; addMonthsNoOverflow clamps to month ends).
 *
 * "Sales" = CONFIRMED money only: paid orders windowed on opened_at,
 * excluding any order that still has a pending-reconciliation tender
 * (P-F7 — those count once the admin approves). F7's confirmed
 * deliveries already re-date opened_at to the confirmation moment, so
 * they land in the window the money actually arrived.
 *
 * There is no scheduler in this stack: [finalizeDueWindows] runs lazily
 * whenever the portal reads targets or the dashboard widget, persisting
 * every fully-elapsed window exactly once (UNIQUE (target, window_start)
 * absorbs races between concurrent fetches). The goal is frozen per
 * window at finalization time — a later amount edit never rewrites
 * history.
 */
final readonly class EvaluateBranchTargetsAction
{
    /** Backfill safety valve per call — a 2-year-old daily target heals
     * across a few fetches instead of one giant request. */
    private const MAX_FINALIZE_PER_CALL = 400;

    /**
     * The window of [$target] containing [$date]: start/end (whole days,
     * inclusive), the 1-based window index, the 1-based elapsed period
     * within it, and the cumulative goal.
     *
     * @return array{start: Carbon, end: Carbon, index: int, elapsed_periods: int, goal: float}
     */
    public function windowFor(BranchTarget $target, Carbon $date): array
    {
        $anchor = $target->starts_on->copy()->startOfDay();
        $day = $date->copy()->startOfDay();
        $n = max(1, (int) $target->window_periods);

        if ($target->period === BranchTargetPeriod::Month) {
            // Months vary in length — locate by month arithmetic, then
            // nudge for anchor-day clamping.
            $monthsSince = ($day->year - $anchor->year) * 12 + ($day->month - $anchor->month);
            $index = intdiv(max(0, $monthsSince), $n);
            $start = $anchor->copy()->addMonthsNoOverflow($index * $n);
            if ($day->lt($start)) {
                $index = max(0, $index - 1);
                $start = $anchor->copy()->addMonthsNoOverflow($index * $n);
            }
            $nextStart = $anchor->copy()->addMonthsNoOverflow(($index + 1) * $n);
            if ($day->gte($nextStart)) {
                $index++;
                $start = $nextStart;
                $nextStart = $anchor->copy()->addMonthsNoOverflow(($index + 1) * $n);
            }
            $end = $nextStart->copy()->subDay();
            // Elapsed ANCHORED periods inside the window (a Jan-31 anchor's
            // period 2 starts Feb-28, not Feb-1 — a raw calendar-month diff
            // would flip a period early), clamped to [1, n].
            $elapsed = 1;
            for ($p = 1; $p < $n; $p++) {
                if ($day->gte($start->copy()->addMonthsNoOverflow($p))) {
                    $elapsed = $p + 1;
                } else {
                    break;
                }
            }
        } else {
            $periodDays = $target->period === BranchTargetPeriod::Week ? 7 : 1;
            $windowDays = $periodDays * $n;
            $daysSince = max(0, (int) $anchor->diffInDays($day));
            $index = intdiv($daysSince, $windowDays);
            $start = $anchor->copy()->addDays($index * $windowDays);
            $end = $start->copy()->addDays($windowDays - 1);
            // max(1,...): Carbon 3's diffInDays is SIGNED — a future-dated
            // target (day < start) must read "period 1", not a negative.
            $elapsed = min($n, max(1, intdiv((int) $start->diffInDays($day), $periodDays) + 1));
        }

        return [
            'start' => $start,
            'end' => $end,
            'index' => $index + 1,
            'elapsed_periods' => $elapsed,
            'goal' => round((float) $target->amount * $n, 3),
        ];
    }

    /**
     * Confirmed sales for the target's branch in [$start, $end] (whole
     * days): paid orders on opened_at, minus anything still awaiting a
     * tender reconciliation.
     */
    public function actualFor(BranchTarget $target, Carbon $start, Carbon $end): float
    {
        return (float) Order::query()
            ->where('company_id', $target->company_id)
            ->where('branch_id', $target->branch_id)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->whereDoesntHave('payments', function ($q): void {
                $q->where('pending_reconciliation', true);
            })
            ->sum('grand_total');
    }

    /**
     * Persist every fully-elapsed, not-yet-finalized window of [$target]
     * (lazy finalization). Idempotent + race-safe.
     *
     * @return int how many windows were finalized this call
     */
    public function finalizeDueWindows(BranchTarget $target, ?Carbon $today = null): int
    {
        // A retired/paused target stops being evaluated: its kept history
        // must not keep growing against a goal nobody targets anymore (a
        // later re-activation backfills naturally via the cursor).
        if (! $target->is_active) {
            return 0;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        if ($target->starts_on->copy()->startOfDay()->gte($today)) {
            return 0;
        }

        // Resume after the newest finalized window (or from the anchor).
        $lastEnd = BranchTargetWindow::query()
            ->where('target_id', $target->id)
            ->max('window_end');
        $cursor = $lastEnd !== null
            ? Carbon::parse($lastEnd)->addDay()->startOfDay()
            : $target->starts_on->copy()->startOfDay();

        $finalized = 0;
        while ($finalized < self::MAX_FINALIZE_PER_CALL) {
            $window = $this->windowFor($target, $cursor);
            // Only FULLY elapsed windows finalize; the current one stays
            // live on the dashboard.
            if (! $window['end']->lt($today)) {
                break;
            }

            // P-F7 money still awaiting reconciliation inside this window:
            // finalizing now would freeze a verdict that EXCLUDES money the
            // admin may confirm tomorrow (approval flips the tender flag but
            // never re-dates the order, so a frozen window would lose it
            // forever). Wait — this and every later window finalize on the
            // first fetch after the decision lands.
            if ($this->hasPendingTenderMoney($target, $window['start'], $window['end'])) {
                break;
            }

            $actual = $this->actualFor($target, $window['start'], $window['end']);
            try {
                BranchTargetWindow::query()->create([
                    'target_id' => $target->id,
                    'company_id' => $target->company_id,
                    'branch_id' => $target->branch_id,
                    'window_start' => $window['start']->toDateString(),
                    'window_end' => $window['end']->toDateString(),
                    'goal_amount' => number_format($window['goal'], 3, '.', ''),
                    'actual_amount' => number_format($actual, 3, '.', ''),
                    'hit' => $actual + 0.0005 >= $window['goal'],
                    'finalized_at' => now(),
                ]);
                $finalized++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent fetch won the race for this window — fine.
            }

            $cursor = $window['end']->copy()->addDay();
        }

        return $finalized;
    }

    /**
     * True while the window contains a paid order whose tender is still
     * pending reconciliation — its money is neither confirmed nor refused
     * yet, so the window's verdict cannot be frozen.
     */
    private function hasPendingTenderMoney(BranchTarget $target, Carbon $start, Carbon $end): bool
    {
        return Order::query()
            ->where('company_id', $target->company_id)
            ->where('branch_id', $target->branch_id)
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ])
            ->whereHas('payments', function ($q): void {
                $q->where('pending_reconciliation', true);
            })
            ->exists();
    }
}

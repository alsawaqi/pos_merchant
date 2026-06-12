<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Targets\CreateBranchTargetAction;
use App\Actions\Pos\Targets\DeleteBranchTargetAction;
use App\Actions\Pos\Targets\EvaluateBranchTargetsAction;
use App\Actions\Pos\Targets\UpdateBranchTargetAction;
use App\Enums\MerchantPermission;
use App\Http\Requests\Pos\Targets\CreateBranchTargetRequest;
use App\Http\Requests\Pos\Targets\UpdateBranchTargetRequest;
use App\Models\BranchTarget;
use App\Models\BranchTargetWindow;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * P-G8 — branch performance targets.
 *
 *   GET    /api/branch-targets               the config page: every target
 *                                            (F5-scoped) with its live
 *                                            current-window progress + the
 *                                            last-12-window history.
 *   GET    /api/branch-targets/performance   the dashboard widget (auth-
 *                                            only): ACTIVE targets' live
 *                                            progress + just-missed windows.
 *   POST   /api/branch-targets               create (replaces the branch's
 *                                            active target).
 *   PATCH  /api/branch-targets/{uuid}        amount / is_active only.
 *   DELETE /api/branch-targets/{uuid}        soft delete (history kept).
 *
 * Both GETs run the LAZY finalizer first — there is no scheduler, so the
 * portal reading targets is what persists fully-elapsed windows.
 * targets.manage gates the config endpoints; the widget is auth-only
 * (every portal user sees their scope's performance).
 */
class BranchTargetsController
{
    private const HISTORY_WINDOWS = 12;

    /** A miss only pops up while it is fresh (ended within N days). */
    private const RECENT_MISS_DAYS = 7;

    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly EvaluateBranchTargetsAction $evaluate,
        private readonly CreateBranchTargetAction $create,
        private readonly UpdateBranchTargetAction $update,
        private readonly DeleteBranchTargetAction $delete,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::TargetsManage);

        $targets = $this->scopedTargets($request)->get();
        foreach ($targets as $target) {
            $this->evaluate->finalizeDueWindows($target);
        }

        return response()->json([
            'data' => $targets->map(fn (BranchTarget $t): array => $this->mapTarget($t))->values()->all(),
        ]);
    }

    public function performance(Request $request): JsonResponse
    {
        // Auth-only by design: every portal user follows their branches'
        // performance; F5 scope shrinks the set below.
        $targets = $this->scopedTargets($request)->where('is_active', true)->get();
        foreach ($targets as $target) {
            $this->evaluate->finalizeDueWindows($target);
        }

        $today = now();
        $rows = [];
        $recentMisses = [];

        foreach ($targets as $target) {
            $window = $this->evaluate->windowFor($target, $today);
            $actual = $this->evaluate->actualFor($target, $window['start'], $window['end']);

            $last12 = BranchTargetWindow::query()
                ->where('target_id', $target->id)
                ->orderByDesc('window_start')
                ->limit(self::HISTORY_WINDOWS)
                ->get();
            $lastWindow = $last12->first();

            $rows[] = [
                'target_uuid' => $target->uuid,
                'branch_uuid' => $target->branch?->uuid,
                'branch_name' => $target->branch?->name,
                'period' => $target->period->value,
                'window_periods' => (int) $target->window_periods,
                'window_start' => $window['start']->toDateString(),
                'window_end' => $window['end']->toDateString(),
                'elapsed_periods' => $window['elapsed_periods'],
                'goal' => number_format($window['goal'], 3, '.', ''),
                'actual' => number_format($actual, 3, '.', ''),
                'progress_pct' => $window['goal'] > 0
                    ? (int) round(min(100, $actual / $window['goal'] * 100))
                    : 0,
                'hit_count' => $last12->where('hit', true)->count(),
                'window_count' => $last12->count(),
                'last_window' => $lastWindow !== null ? [
                    'window_start' => $lastWindow->window_start->toDateString(),
                    'window_end' => $lastWindow->window_end->toDateString(),
                    'goal_amount' => (string) $lastWindow->goal_amount,
                    'actual_amount' => (string) $lastWindow->actual_amount,
                    'hit' => (bool) $lastWindow->hit,
                ] : null,
            ];

            // "Just missed": the most recent finalized window, when it was
            // a miss that ended within the popup horizon.
            if ($lastWindow !== null
                && ! $lastWindow->hit
                && $lastWindow->window_end->gte($today->copy()->subDays(self::RECENT_MISS_DAYS)->startOfDay())) {
                $recentMisses[] = [
                    'branch_name' => $target->branch?->name,
                    'window_start' => $lastWindow->window_start->toDateString(),
                    'window_end' => $lastWindow->window_end->toDateString(),
                    'goal_amount' => (string) $lastWindow->goal_amount,
                    'actual_amount' => (string) $lastWindow->actual_amount,
                ];
            }
        }

        return response()->json([
            'data' => $rows,
            'recent_misses' => $recentMisses,
        ]);
    }

    public function store(CreateBranchTargetRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::TargetsManage);

        try {
            $target = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->mapTarget($target->load('branch'))], 201);
    }

    public function update(UpdateBranchTargetRequest $request, BranchTarget $target): JsonResponse
    {
        $this->ensure($request, MerchantPermission::TargetsManage);

        try {
            $updated = $this->update->handle($target, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->mapTarget($updated->load('branch'))]);
    }

    public function destroy(Request $request, BranchTarget $target): JsonResponse
    {
        $this->ensure($request, MerchantPermission::TargetsManage);

        $this->delete->handle($target, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * Tenant + F5 scope: the list silently shrinks to the actor's
     * branches (the house rule for input-less lists).
     */
    private function scopedTargets(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $allowed = $request->user()?->allowedBranchIds();

        return BranchTarget::query()
            ->with('branch:id,uuid,name')
            ->where('company_id', $this->tenant->requiredId())
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->orderBy('branch_id')
            ->orderByDesc('is_active');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTarget(BranchTarget $target): array
    {
        $today = now();
        $current = null;
        if ($target->is_active) {
            $window = $this->evaluate->windowFor($target, $today);
            $actual = $this->evaluate->actualFor($target, $window['start'], $window['end']);
            $current = [
                'window_start' => $window['start']->toDateString(),
                'window_end' => $window['end']->toDateString(),
                'elapsed_periods' => $window['elapsed_periods'],
                'goal' => number_format($window['goal'], 3, '.', ''),
                'actual' => number_format($actual, 3, '.', ''),
                'progress_pct' => $window['goal'] > 0
                    ? (int) round(min(100, $actual / $window['goal'] * 100))
                    : 0,
            ];
        }

        $history = BranchTargetWindow::query()
            ->where('target_id', $target->id)
            ->orderByDesc('window_start')
            ->limit(self::HISTORY_WINDOWS)
            ->get();

        return [
            'uuid' => $target->uuid,
            'branch_uuid' => $target->branch?->uuid,
            'branch_name' => $target->branch?->name,
            'period' => $target->period->value,
            'amount' => (string) $target->amount,
            'window_periods' => (int) $target->window_periods,
            'starts_on' => $target->starts_on->toDateString(),
            'is_active' => (bool) $target->is_active,
            'current' => $current,
            'hit_count' => $history->where('hit', true)->count(),
            'window_count' => $history->count(),
            'history' => $history->map(fn (BranchTargetWindow $w): array => [
                'window_start' => $w->window_start->toDateString(),
                'window_end' => $w->window_end->toDateString(),
                'goal_amount' => (string) $w->goal_amount,
                'actual_amount' => (string) $w->actual_amount,
                'hit' => (bool) $w->hit,
            ])->values()->all(),
        ];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}

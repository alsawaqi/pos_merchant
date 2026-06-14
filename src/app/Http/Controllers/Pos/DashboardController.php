<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\DashboardSummaryAction;
use App\Actions\Pos\Reports\SalesComparisonAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7b-7 — merchant Dashboard summary endpoint.
 *
 * One GET that the landing page hits on mount. Gated under
 * reports.view -- every dashboard widget is a small report
 * cluster, so the same gate makes sense.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardSummaryAction $summary,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ReportsView->value)) {
            abort(403);
        }

        // P-G5 — a branch-restricted user's dashboard aggregates only
        // their branches.
        return response()->json(['data' => $this->summary->handle($user->allowedBranchIds())]);
    }

    /**
     * Period-over-period sales comparison (this week/month vs the previous one,
     * or a prior period via ?offset=N). Shared by the dashboard (full scope) and
     * the branch control center (?branch_id=<id>, narrowed to that one branch).
     */
    public function salesComparison(Request $request, SalesComparisonAction $action): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ReportsView->value)) {
            abort(403);
        }

        $period = $request->query('period') === 'month' ? 'month' : 'week';
        $offset = max(0, (int) $request->query('offset', 0));

        // Default to the actor's full branch scope; narrow to one branch when
        // requested (a branch-restricted user may only narrow within their scope
        // — the action also filters by company_id, so no cross-tenant read).
        $allowed = $user->allowedBranchIds();
        $branchIds = $allowed;
        $requested = $request->query('branch_id');
        if ($requested !== null && $requested !== '') {
            $bid = (int) $requested;
            if ($allowed !== null && ! in_array($bid, $allowed, true)) {
                abort(403);
            }
            $branchIds = [$bid];
        }

        return response()->json(['data' => $action->handle($branchIds, $period, $offset)]);
    }
}

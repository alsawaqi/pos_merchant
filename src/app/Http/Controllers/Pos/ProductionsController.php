<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\ProductionSummaryAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\Production\ProductionResource;
use App\Models\Branch;
use App\Models\Production;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P-G1 — kitchen production history (READ-ONLY).
 *
 *   GET /api/productions → paginated batches, newest first, filterable by
 *                          branch (uuid), status, and started_at date range.
 *
 * Batches are created / finished / cancelled exclusively from the POS
 * device through pos_api (production is online-only; the server validates
 * fresh ingredient balances at each phase). The portal answers: who cooked
 * what, when, how much, what the recipe said vs what the kitchen actually
 * used (std vs extra lines), and how long the batch took.
 *
 * Gated on production.view; tenant-scoped via MerchantTenantContext.
 */
class ProductionsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly ProductionSummaryAction $summaryAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request);

        $request->validate([
            'branch_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:in_progress,finished,cancelled'],
            // date_format (not `date`): the action concatenates "$d 00:00:00"
            // into the started_at predicate, so a time-bearing value would
            // build a malformed timestamp literal (a 500 on Postgres). The UI
            // only ever sends Y-m-d; reject anything else at the edge.
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        // P-G5 — a branch-restricted user's default list shrinks to
        // their scope.
        $allowed = $request->user()?->allowedBranchIds();

        $query = Production::query()
            ->where('company_id', $this->tenant->requiredId())
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->with([
                'product:id,uuid,name,name_ar',
                'branch:id,uuid,name',
                'lines.ingredient:id,name,name_ar,unit',
                'startedByStaff:id,name',
                'finishedByStaff:id,name',
                'cancelledByStaff:id,name',
                'cancelApprovedByStaff:id,name',
            ]);

        $branchUuid = $request->query('branch_uuid');
        if (is_string($branchUuid) && $branchUuid !== '') {
            $branch = Branch::query()
                ->where('company_id', $this->tenant->requiredId())
                ->where('uuid', $branchUuid)
                ->first();
            // P-G5 — an EXPLICIT request for an in-tenant branch outside
            // the scope is a 403 (spec: rejected, not silently hidden).
            if ($branch !== null) {
                \App\Support\BranchScope::ensureBranch($request->user(), $branch);
            }
            // Unknown / foreign branch matches nothing rather than leaking.
            $query->where('branch_id', $branch?->id ?? -1);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $from = $request->query('from');
        if (is_string($from) && $from !== '') {
            $query->where('started_at', '>=', $from . ' 00:00:00');
        }

        $to = $request->query('to');
        if (is_string($to) && $to !== '') {
            $query->where('started_at', '<=', $to . ' 23:59:59');
        }

        $perPage = min((int) $request->query('per_page', 25), 200);

        $page = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Production $p): array => (new ProductionResource($p))->resolve($request));

        return response()->json($page);
    }

    /**
     * Graphical-view aggregates over the SAME filters as the list — totals,
     * by-product / by-staff, daily trend, status mix, and a recent-batch
     * timeline. Drives the Production page charts + Gantt timeline.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->ensure($request);

        $request->validate([
            'branch_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:in_progress,finished,cancelled'],
            // See index(): date_format guards the concatenated started_at predicate.
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $allowed = $request->user()?->allowedBranchIds();

        // Resolve an explicit branch filter the same way the list does:
        // an in-tenant branch outside the actor's scope is a 403; an
        // unknown/foreign branch matches nothing (branch_id = -1).
        $branchId = null;
        $branchUuid = $request->query('branch_uuid');
        if (is_string($branchUuid) && $branchUuid !== '') {
            $branch = Branch::query()
                ->where('company_id', $this->tenant->requiredId())
                ->where('uuid', $branchUuid)
                ->first();
            if ($branch !== null) {
                \App\Support\BranchScope::ensureBranch($request->user(), $branch);
            }
            $branchId = $branch?->id ?? -1;
        }

        $statusFilter = $request->query('status');
        $payload = $this->summaryAction->handle(
            $this->tenant->requiredId(),
            $allowed,
            $branchId,
            is_string($statusFilter) ? $statusFilter : null,
            is_string($request->query('from')) ? $request->query('from') : null,
            is_string($request->query('to')) ? $request->query('to') : null,
        );

        return response()->json(['data' => $payload]);
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ProductionView->value)) {
            abort(403);
        }
    }
}

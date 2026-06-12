<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request);

        $request->validate([
            'branch_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:in_progress,finished,cancelled'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
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

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ProductionView->value)) {
            abort(403);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\AllocateRestockRequestAction;
use App\Actions\Pos\Inventory\CancelRestockRequestAction;
use App\Actions\Pos\Inventory\CreateRestockRequestAction;
use App\Actions\Pos\Inventory\ReviewRestockRequestAction;
use App\Actions\Pos\Inventory\SubmitRestockRequestAction;
use App\Actions\Pos\Inventory\SuggestRestockAction;
use App\Actions\Pos\Inventory\UpdateRestockRequestAction;
use App\Enums\MerchantPermission;
use App\Enums\RestockRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\AllocateRestockRequestRequest;
use App\Http\Requests\Pos\Inventory\CancelRestockRequestRequest;
use App\Http\Requests\Pos\Inventory\CreateRestockRequestRequest;
use App\Http\Requests\Pos\Inventory\ReviewRestockRequestRequest;
use App\Http\Requests\Pos\Inventory\UpdateRestockRequestRequest;
use App\Http\Resources\Pos\Inventory\RestockRequestResource;
use App\Models\Branch;
use App\Models\RestockRequest;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 5c — restock request lifecycle.
 *
 *   GET    /api/restock-requests                        → list (all branches)
 *   GET    /api/restock-requests/{request:uuid}         → show one
 *   POST   /api/branches/{branch:uuid}/restock-requests → create draft
 *   PATCH  /api/restock-requests/{request:uuid}         → replace lines (Draft only)
 *   POST   /api/restock-requests/{request:uuid}/submit  → Draft → Submitted
 *   POST   /api/restock-requests/{request:uuid}/approve → Submitted → Approved
 *   POST   /api/restock-requests/{request:uuid}/reject  → Submitted → Rejected
 *   POST   /api/restock-requests/{request:uuid}/cancel  → Draft|Submitted → Cancelled
 *   POST   /api/restock-requests/{request:uuid}/allocate→ Approved → Fulfilled (+ stock movements)
 *
 * Permission gating:
 *   - InventoryView for index/show
 *   - RestockRequestCreate for create + update + submit + cancel
 *     (the requester side of the workflow — branch staff)
 *   - RestockRequestReview for approve + reject + allocate
 *     (the HQ side of the workflow)
 *
 * All endpoints tenant-scoped via MerchantTenantContext.
 * Branch ownership re-checked on create; request ownership
 * re-checked on every other endpoint.
 *
 * Index filters:
 *   ?status=<one of enum values>   → filter to one status
 *   ?branch=<uuid>                 → filter to one branch
 */
class RestockRequestsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateRestockRequestAction $create,
        private readonly UpdateRestockRequestAction $update,
        private readonly SubmitRestockRequestAction $submit,
        private readonly ReviewRestockRequestAction $review,
        private readonly CancelRestockRequestAction $cancel,
        private readonly AllocateRestockRequestAction $allocate,
        private readonly SuggestRestockAction $suggest,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $companyId = $this->tenant->requiredId();

        // P-G5 — a scoped user's HQ inbox shrinks to their branches.
        $allowed = $request->user()?->allowedBranchIds();

        $query = RestockRequest::query()
            ->where('company_id', $companyId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->with(['lines.ingredient', 'branch', 'requestedBy', 'reviewedBy']);

        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            if (in_array($status, RestockRequestStatus::values(), true)) {
                $query->where('status', $status);
            } else {
                // Unknown status — fail-closed.
                $query->where('status', '__never_matches__');
            }
        }

        if ($request->filled('branch')) {
            $branchId = Branch::query()
                ->where('uuid', $request->query('branch'))
                ->where('company_id', $companyId)
                ->value('id');
            // P-G5 — an explicit in-tenant filter outside the scope is a 403.
            if ($branchId !== null && $allowed !== null && ! in_array((int) $branchId, $allowed, true)) {
                abort(403, 'Your account is restricted to specific branches.');
            }
            $query->where('branch_id', $branchId ?? -1);
        }

        $requests = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return RestockRequestResource::collection($requests);
    }

    public function show(Request $request, RestockRequest $restockRequest): RestockRequestResource
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($restockRequest);

        $restockRequest->load(['lines.ingredient', 'branch', 'requestedBy', 'reviewedBy']);

        return RestockRequestResource::make($restockRequest);
    }

    /**
     * GET /api/branches/{branch:uuid}/restock-suggestions
     *
     * Non-binding restock suggestions for a branch: per ingredient, a proposed
     * quantity derived from current stock + min_stock_threshold + the trailing
     * consumption rate. The manager pre-fills a restock request from this, then
     * edits + submits via the normal flow. A READ — gated on InventoryView, so
     * a read-only supervisor can see it without restock authority.
     *
     * ?window_days (consumption lookback, default 30) + ?cover_days (target
     * days-of-stock to carry, default 14), each clamped to 1..365.
     */
    public function suggestions(Request $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $windowDays = max(1, min(365, (int) $request->query('window_days', 30)));
        $coverDays = max(1, min(365, (int) $request->query('cover_days', 14)));

        $suggestions = $this->suggest->handle($branch, $windowDays, $coverDays);

        return response()->json([
            'data' => $suggestions,
            'meta' => [
                'branch_id' => $branch->id,
                'branch_uuid' => $branch->uuid,
                'window_days' => $windowDays,
                'cover_days' => $coverDays,
            ],
        ]);
    }

    public function store(CreateRestockRequestRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestCreate);
        $this->refuseIfBranchNotInTenant($branch);

        try {
            $req = $this->create->handle(
                branch: $branch,
                lines: $request->validated()['lines'],
                actor: $request->user(),
                note: $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new RestockRequestResource($req))->resolve($request),
        ], 201);
    }

    public function update(UpdateRestockRequestRequest $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestCreate);
        $this->refuseIfNotInTenant($restockRequest);

        try {
            $updated = $this->update->handle(
                $restockRequest,
                $request->validated()['lines'],
                $request->user(),
                $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    public function submit(Request $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestCreate);
        $this->refuseIfNotInTenant($restockRequest);

        try {
            $updated = $this->submit->handle($restockRequest, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    public function approve(ReviewRestockRequestRequest $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestReview);
        $this->refuseIfNotInTenant($restockRequest);

        try {
            $updated = $this->review->approve(
                $restockRequest,
                $request->user(),
                $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    public function reject(ReviewRestockRequestRequest $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestReview);
        $this->refuseIfNotInTenant($restockRequest);

        // The action enforces non-empty note on reject; we relay
        // the validation error as 422.
        try {
            $updated = $this->review->reject(
                $restockRequest,
                $request->user(),
                (string) ($request->validated()['note'] ?? ''),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    public function cancel(CancelRestockRequestRequest $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestCreate);
        $this->refuseIfNotInTenant($restockRequest);

        try {
            $updated = $this->cancel->handle(
                $restockRequest,
                $request->user(),
                $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    public function allocate(AllocateRestockRequestRequest $request, RestockRequest $restockRequest): RestockRequestResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::RestockRequestReview);
        $this->refuseIfNotInTenant($restockRequest);

        // Normalise the allocations map: keys come in as strings
        // from the JSON body, but the Action expects int keys
        // (line ids). Convert before passing.
        $rawAllocations = $request->validated()['allocations'] ?? [];
        $allocations = [];
        foreach ($rawAllocations as $lineId => $qty) {
            $allocations[(int) $lineId] = $qty;
        }

        try {
            $updated = $this->allocate->handle(
                $restockRequest,
                $allocations,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return RestockRequestResource::make($updated);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(RestockRequest $req): void
    {
        if ((int) $req->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfBranchNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Branch\UpdateMerchantBranchAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Branch\UpdateMerchantBranchRequest;
use App\Http\Resources\Pos\Branch\BranchResource;
use App\Models\Branch;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Merchant portal — manage YOUR OWN company's branches.
 *
 *   GET   /api/pos/branches              → list (full payload)
 *   GET   /api/pos/branches/{branch}     → show one
 *   PATCH /api/pos/branches/{branch}     → update (rename, hours,
 *                                          contact, geo, status*)
 *
 * Auto-scoped to the actor's company via MerchantTenantContext.
 * UUID-bound routes — internal ids stay out of the address bar.
 *
 * Companion to {@see \App\Http\Controllers\Portal\BranchesController}
 * which keeps its lean shape for the Portal Users branch-scope
 * picker; the controller here exists separately because the
 * full payload is wasteful for the picker and the picker has no
 * permission gate (every authed user needs to render it).
 *
 * Status flip (active ↔ inactive) is double-gated:
 *   - validator accepts it
 *   - UpdateMerchantBranchAction refuses unless the actor holds
 *     BranchesTransitionStatus — only SuperAdmin by default
 *
 * No create / delete here on purpose — those operations have
 * CR/regulatory + device-fleet impact and stay on pos_admin.
 */
class BranchesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly UpdateMerchantBranchAction $update,
    ) {}

    /**
     * GET /api/pos/branches
     *
     * Full payload, ordered alphabetically by name. No
     * pagination — a typical merchant has < 20 branches; we'll
     * add server-side pagination when any tenant exceeds that.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::BranchesView);

        $branches = Branch::query()
            ->where('company_id', $this->tenant->requiredId())
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    /**
     * GET /api/pos/branches/{branch:uuid}
     *
     * Drilldown for the Edit modal's initial state. Same payload
     * as index() but per-row — saves us re-listing if the modal
     * is opened from a deep link.
     */
    public function show(Request $request, Branch $branch): BranchResource
    {
        $this->ensure($request, MerchantPermission::BranchesView);
        $this->refuseIfNotInTenant($branch);

        return BranchResource::make($branch);
    }

    /**
     * PATCH /api/pos/branches/{branch:uuid}
     *
     * Partial update. Validation passes the payload as-is; the
     * action layer enforces the merchant-editable whitelist + the
     * extra BranchesTransitionStatus gate on status.
     */
    public function update(UpdateMerchantBranchRequest $request, Branch $branch): BranchResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::BranchesUpdate);
        $this->refuseIfNotInTenant($branch);

        try {
            $updated = $this->update->handle($branch, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            // Action throws on cross-tenant (404 above already
            // covered) + on missing BranchesTransitionStatus when
            // a status field is present. Surface the second as
            // 403 because that's a permission boundary, not a
            // validation error.
            $isPermissionError = str_contains($e->getMessage(), 'permission');
            return response()->json(['message' => $e->getMessage()], $isPermissionError ? 403 : 422);
        }

        return BranchResource::make($updated);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * UUID route binding doesn't validate company ownership.
     * Without this, any merchant with branches.view could pass
     * another merchant's branch uuid and read/update it.
     */
    private function refuseIfNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

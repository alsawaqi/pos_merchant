<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Branch\UpdateBranchReceiptTemplateAction;
use App\Actions\Pos\Branch\UpdateMerchantBranchAction;
use App\Actions\Pos\Reports\BranchActivityAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Branch\UpdateBranchReceiptTemplateRequest;
use App\Http\Requests\Pos\Branch\UpdateMerchantBranchRequest;
use App\Http\Resources\Pos\Branch\BranchResource;
use App\Http\Resources\Pos\Branch\DeviceResource;
use App\Http\Resources\Pos\Staff\PosStaffResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\PosStaff;
use App\Models\Product;
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
        private readonly UpdateBranchReceiptTemplateAction $updateReceiptTemplate,
        private readonly BranchActivityAction $branchActivity,
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

    /**
     * PUT /api/pos/branches/{branch:uuid}/receipt-template
     *
     * Replace this branch's custom POS-receipt template (header /
     * CR / VAT / footer). The whole template is sent as one object;
     * the action normalizes + audits it. branches.update gated.
     */
    public function updateReceiptTemplate(UpdateBranchReceiptTemplateRequest $request, Branch $branch): BranchResource
    {
        $this->ensure($request, MerchantPermission::BranchesUpdate);
        $this->refuseIfNotInTenant($branch);

        $updated = $this->updateReceiptTemplate->handle($branch, $request->validated(), $request->user());

        return BranchResource::make($updated);
    }

    /**
     * GET /api/pos/branches/{branch:uuid}/devices
     *
     * Read-only list of the devices the platform admin has assigned to
     * this branch. The merchant SEES them (type, status, last seen) but
     * cannot control them -- device provisioning lives in pos_admin.
     */
    public function devices(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::BranchesView);
        $this->refuseIfNotInTenant($branch);

        $devices = Device::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get();

        return DeviceResource::collection($devices);
    }

    /**
     * GET /api/pos/branches/{branch:uuid}/products  (v2 #11)
     *
     * Products carried at this branch (have a pos_branch_product row),
     * with the per-branch availability + unit stock from the pivot.
     * catalogue.view gated. NULL stock_qty = not unit-tracked here.
     */
    public function products(Request $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfNotInTenant($branch);

        $products = Product::query()
            ->where('company_id', $this->tenant->requiredId())
            ->whereHas('branchProducts', fn ($q) => $q->where('branch_id', $branch->id))
            ->with(['branchProducts' => fn ($q) => $q->where('branch_id', $branch->id)])
            ->orderBy('name')
            ->get();

        $data = $products->map(static function (Product $p): array {
            $bp = $p->branchProducts->first();

            return [
                'product_id' => (int) $p->id,
                'uuid' => $p->uuid,
                'name' => (string) $p->name,
                'base_price' => (string) $p->base_price,
                'stock_mode' => (string) $p->stock_mode,
                'is_available' => $bp !== null ? (bool) $bp->is_available : true,
                'stock_qty' => $bp !== null && $bp->stock_qty !== null ? (string) $bp->stock_qty : null,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/pos/branches/{branch:uuid}/staff  (v2 #11)
     *
     * Staff assigned to this branch (pos_staff.branch_id). pos_staff.view
     * gated. Phone is decrypted by the resource; pin_hash never serialized.
     */
    public function staff(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::PosStaffView);
        $this->refuseIfNotInTenant($branch);

        $staff = PosStaff::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get();

        return PosStaffResource::collection($staff);
    }

    /**
     * GET /api/pos/branches/{branch:uuid}/activity  (v2 #11)
     *
     * Branch sales snapshot (today + MTD) + recent orders / shifts /
     * stock movements. reports.view gated.
     */
    public function activity(Request $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);
        $this->refuseIfNotInTenant($branch);

        return response()->json([
            'data' => $this->branchActivity->handle($this->tenant->requiredId(), (int) $branch->id),
        ]);
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

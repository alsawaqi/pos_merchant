<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateAddOnGroupAction;
use App\Actions\Pos\Catalogue\DeleteAddOnGroupAction;
use App\Actions\Pos\Catalogue\UpdateAddOnGroupAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\CreateAddOnGroupRequest;
use App\Http\Requests\Pos\Catalogue\UpdateAddOnGroupRequest;
use App\Http\Resources\Pos\Catalogue\AddOnGroupResource;
use App\Models\AddOnGroup;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 4.9 — manage add-on groups.
 *
 *   GET    /api/addon-groups                        → list
 *   POST   /api/addon-groups                        → create
 *   PATCH  /api/addon-groups/{addonGroup:uuid}      → update
 *   DELETE /api/addon-groups/{addonGroup:uuid}      → delete (refuses
 *                                                     if attached to
 *                                                     products)
 *
 * Add-ons (the options inside a group) are managed by the
 * sibling AddOnsController under a nested route.
 *
 * Read gated on CatalogueView, mutations on CatalogueManage.
 * Tenant-scoped via MerchantTenantContext.
 */
class AddOnGroupsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateAddOnGroupAction $create,
        private readonly UpdateAddOnGroupAction $update,
        private readonly DeleteAddOnGroupAction $delete,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $groups = AddOnGroup::query()
            ->where('company_id', $this->tenant->requiredId())
            // v2 #6: product-owned groups are managed from their product, not
            // the shared add-ons list — exclude them here.
            ->whereNull('owner_product_id')
            ->with(['addOns' => function ($q): void {
                $q->orderBy('display_order')->orderBy('name');
            }])
            // Phase B — bound categories ship as category_ids on the resource.
            ->with('categories')
            ->withCount(['products', 'addOns'])
            ->orderBy('is_global', 'desc')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return AddOnGroupResource::collection($groups);
    }

    public function store(CreateAddOnGroupRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        $group = $this->create->handle($request->validated(), $request->user());

        return response()->json([
            'data' => (new AddOnGroupResource($group))->resolve($request),
        ], 201);
    }

    public function update(UpdateAddOnGroupRequest $request, AddOnGroup $addonGroup): AddOnGroupResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($addonGroup);

        try {
            $updated = $this->update->handle($addonGroup, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return AddOnGroupResource::make($updated);
    }

    public function destroy(Request $request, AddOnGroup $addonGroup): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($addonGroup);

        try {
            $this->delete->handle($addonGroup, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(AddOnGroup $group): void
    {
        if ((int) $group->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

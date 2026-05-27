<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateAddOnAction;
use App\Actions\Pos\Catalogue\DeleteAddOnAction;
use App\Actions\Pos\Catalogue\UpdateAddOnAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\CreateAddOnRequest;
use App\Http\Requests\Pos\Catalogue\UpdateAddOnRequest;
use App\Http\Resources\Pos\Catalogue\AddOnResource;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 4.9 — manage add-ons (the options inside a group).
 *
 *   POST   /api/addon-groups/{addonGroup:uuid}/addons    → create
 *   PATCH  /api/addons/{addon:uuid}                      → update
 *   DELETE /api/addons/{addon:uuid}                      → soft delete
 *
 * Read is part of the group's index — when fetching addon
 * groups we eager-load their addons. No standalone list
 * endpoint here.
 *
 * Read gate inherited from CatalogueView (via the group
 * endpoint), mutations on CatalogueManage.
 */
class AddOnsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateAddOnAction $create,
        private readonly UpdateAddOnAction $update,
        private readonly DeleteAddOnAction $delete,
    ) {}

    public function store(CreateAddOnRequest $request, AddOnGroup $addonGroup): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfGroupNotInTenant($addonGroup);

        try {
            $addon = $this->create->handle($addonGroup, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new AddOnResource($addon))->resolve($request),
        ], 201);
    }

    public function update(UpdateAddOnRequest $request, AddOn $addon): AddOnResource
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($addon);

        $updated = $this->update->handle($addon, $request->validated(), $request->user());

        return AddOnResource::make($updated);
    }

    public function destroy(Request $request, AddOn $addon): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($addon);

        $this->delete->handle($addon, $request->user());

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfGroupNotInTenant(AddOnGroup $group): void
    {
        if ((int) $group->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfNotInTenant(AddOn $addon): void
    {
        if ((int) $addon->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

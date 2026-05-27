<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\CreateSupplierAction;
use App\Actions\Pos\Inventory\DeleteSupplierAction;
use App\Actions\Pos\Inventory\UpdateSupplierAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\CreateSupplierRequest;
use App\Http\Requests\Pos\Inventory\UpdateSupplierRequest;
use App\Http\Resources\Pos\Inventory\SupplierResource;
use App\Models\Supplier;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 5a — suppliers CRUD.
 *
 *   GET    /api/suppliers           → list with ingredients_count
 *   POST   /api/suppliers           → create
 *   PATCH  /api/suppliers/{uuid}    → update
 *   DELETE /api/suppliers/{uuid}    → soft delete (refuses if any
 *                                     ingredient still references)
 */
class SuppliersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateSupplierAction $create,
        private readonly UpdateSupplierAction $update,
        private readonly DeleteSupplierAction $delete,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $suppliers = Supplier::query()
            ->where('company_id', $this->tenant->requiredId())
            ->withCount('ingredients')
            ->orderBy('name')
            ->get();

        return SupplierResource::collection($suppliers);
    }

    public function store(CreateSupplierRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);

        $supplier = $this->create->handle($request->validated(), $request->user());

        return response()->json([
            'data' => (new SupplierResource($supplier))->resolve($request),
        ], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($supplier);

        $updated = $this->update->handle($supplier, $request->validated(), $request->user());

        return SupplierResource::make($updated);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($supplier);

        try {
            $this->delete->handle($supplier, $request->user());
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

    private function refuseIfNotInTenant(Supplier $supplier): void
    {
        if ((int) $supplier->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

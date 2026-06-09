<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\CreateIngredientUnitAction;
use App\Actions\Pos\Inventory\DeleteIngredientUnitAction;
use App\Actions\Pos\Inventory\UpdateIngredientUnitAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\CreateIngredientUnitRequest;
use App\Http\Requests\Pos\Inventory\UpdateIngredientUnitRequest;
use App\Http\Resources\Pos\Inventory\IngredientAltUnitResource;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * v2 #13 — an ingredient's alternate units (nested under the ingredient).
 *
 *   GET    /api/ingredients/{ingredient:uuid}/units              → list
 *   POST   /api/ingredients/{ingredient:uuid}/units              → create
 *   PATCH  /api/ingredients/{ingredient:uuid}/units/{unit:uuid}  → update
 *   DELETE /api/ingredients/{ingredient:uuid}/units/{unit:uuid}  → soft delete
 *
 * Read gated on InventoryView; mutations on InventoryManage. Both the ingredient
 * and (where present) the unit are checked against the actor's tenant.
 */
class IngredientUnitsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateIngredientUnitAction $create,
        private readonly UpdateIngredientUnitAction $update,
        private readonly DeleteIngredientUnitAction $delete,
    ) {}

    public function index(Request $request, Ingredient $ingredient): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfIngredientNotInTenant($ingredient);

        return IngredientAltUnitResource::collection($ingredient->altUnits()->get());
    }

    public function store(CreateIngredientUnitRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfIngredientNotInTenant($ingredient);

        try {
            $unit = $this->create->handle($ingredient, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new IngredientAltUnitResource($unit))->resolve($request),
        ], 201);
    }

    public function update(UpdateIngredientUnitRequest $request, Ingredient $ingredient, IngredientAltUnit $unit): IngredientAltUnitResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfIngredientNotInTenant($ingredient);
        $this->refuseIfUnitNotOnIngredient($unit, $ingredient);

        $updated = $this->update->handle($unit, $request->validated(), $request->user());

        return IngredientAltUnitResource::make($updated);
    }

    public function destroy(Request $request, Ingredient $ingredient, IngredientAltUnit $unit): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfIngredientNotInTenant($ingredient);
        $this->refuseIfUnitNotOnIngredient($unit, $ingredient);

        $this->delete->handle($unit, $request->user());

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfIngredientNotInTenant(Ingredient $ingredient): void
    {
        if ((int) $ingredient->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfUnitNotOnIngredient(IngredientAltUnit $unit, Ingredient $ingredient): void
    {
        if ((int) $unit->ingredient_id !== (int) $ingredient->id) {
            abort(404);
        }
    }
}

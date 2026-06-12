<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\CreateIngredientAction;
use App\Actions\Pos\Inventory\DeleteIngredientAction;
use App\Actions\Pos\Inventory\UpdateIngredientAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\CreateIngredientRequest;
use App\Http\Requests\Pos\Inventory\UpdateIngredientRequest;
use App\Http\Resources\Pos\Inventory\IngredientPurchaseResource;
use App\Http\Resources\Pos\Inventory\IngredientResource;
use App\Models\Ingredient;
use App\Models\IngredientPurchase;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 5a — ingredients master CRUD.
 *
 *   GET    /api/ingredients              → list
 *   POST   /api/ingredients              → create
 *   PATCH  /api/ingredients/{uuid}       → update
 *   DELETE /api/ingredients/{uuid}       → soft delete (refuses
 *                                          if any branch holds
 *                                          non-zero stock)
 *
 * Read gated on InventoryView; mutations on InventoryManage.
 * Tenant-scoped via MerchantTenantContext.
 */
class IngredientsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateIngredientAction $create,
        private readonly UpdateIngredientAction $update,
        private readonly DeleteIngredientAction $delete,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $ingredients = Ingredient::query()
            ->where('company_id', $this->tenant->requiredId())
            ->with('primarySupplier', 'altUnits')
            ->orderBy('name')
            ->get();

        return IngredientResource::collection($ingredients);
    }

    public function store(CreateIngredientRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);

        try {
            $ingredient = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $ingredient->load('primarySupplier');

        return response()->json([
            'data' => (new IngredientResource($ingredient))->resolve($request),
        ], 201);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): IngredientResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);

        try {
            $updated = $this->update->handle($ingredient, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $updated->load('primarySupplier');

        return IngredientResource::make($updated);
    }

    /**
     * GET /api/ingredients/{uuid}/purchases
     *
     * Phase A — the batch history (Additions §2.4 "historical
     * batches preserved"). Newest first, paginated.
     */
    public function purchases(Request $request, Ingredient $ingredient): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($ingredient);

        // P-G5 — purchase batches are branch-scoped rows.
        $allowed = $request->user()?->allowedBranchIds();

        $perPage = min((int) $request->query('per_page', 25), 100);

        return IngredientPurchaseResource::collection(
            IngredientPurchase::query()
                ->where('ingredient_id', $ingredient->id)
                ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
                ->with(['branch', 'supplier'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);

        try {
            $this->delete->handle($ingredient, $request->user());
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

    private function refuseIfNotInTenant(Ingredient $ingredient): void
    {
        if ((int) $ingredient->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

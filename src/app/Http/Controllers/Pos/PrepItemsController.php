<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\DeletePrepItemAction;
use App\Actions\Pos\Catalogue\SavePrepItemAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\SavePrepItemRequest;
use App\Http\Resources\Pos\Catalogue\PrepItemResource;
use App\Models\AddOnConsumption;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\ProductRecipe;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\PrepItemHistory;
use App\Support\Recipes\PrepUsage;
use App\Support\Recipes\RecipeQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * LAUNCH-P3 P3-4 — prep items (a sauce, a dough): an ingredient row with its
 * own recipe per batch and a yield, used by product, cooked-product and add-on
 * recipes like an ingredient and exploded into its raw ingredients at sale.
 * A prep item has NO stock of its own.
 *
 *   GET    /api/prep-items                 → list (cost per base unit + per batch, depth, usage)
 *   POST   /api/prep-items                 → create
 *   GET    /api/prep-items/{uuid}          → one, for the editor
 *   PATCH  /api/prep-items/{uuid}          → edit (name, yield, recipe, note)
 *   DELETE /api/prep-items/{uuid}          → soft delete (refused while used)
 *   GET    /api/prep-items/{uuid}/history  → the recipe history (P3-2)
 *
 * Read: catalogue.view OR inventory.view. Writes: "Edit recipes"
 * (catalogue.recipes.manage, P3-3) — re-checked in the actions.
 */
class PrepItemsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SavePrepItemAction $save,
        private readonly DeletePrepItemAction $delete,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanRead($request);
        $companyId = $this->tenant->requiredId();

        $items = Ingredient::query()
            ->where('company_id', $companyId)
            ->prepItems()
            ->with(['prepRecipeLines.ingredient.altUnits'])
            ->orderBy('name')
            ->get();

        $ids = $items->pluck('id')->all();
        $products = ProductRecipe::query()->whereIn('ingredient_id', $ids)->selectRaw('ingredient_id, COUNT(*) AS n')->groupBy('ingredient_id')->pluck('n', 'ingredient_id');
        $addons = AddOnConsumption::query()->whereIn('ingredient_id', $ids)->selectRaw('ingredient_id, COUNT(*) AS n')->groupBy('ingredient_id')->pluck('n', 'ingredient_id');
        $preps = IngredientRecipe::query()->whereIn('ingredient_id', $ids)
            ->whereHas('prepItem', static fn ($q) => $q->whereNull('deleted_at'))
            ->selectRaw('ingredient_id, COUNT(*) AS n')->groupBy('ingredient_id')->pluck('n', 'ingredient_id');

        $data = $items->map(function (Ingredient $item) use ($request, $products, $addons, $preps): array {
            $resource = new PrepItemResource($item);
            $resource->usedBy = [
                'product_recipes' => (int) ($products[$item->id] ?? 0),
                'addon_lines' => (int) ($addons[$item->id] ?? 0),
                'prep_recipes' => (int) ($preps[$item->id] ?? 0),
            ];

            return $resource->resolve($request);
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(SavePrepItemRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueRecipesManage);

        try {
            $prep = $this->save->create($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($request, $prep)], 201);
    }

    public function show(Request $request, Ingredient $prepItem): JsonResponse
    {
        $this->ensureCanRead($request);
        $this->refuseIfNotPrep($prepItem);

        return response()->json(['data' => $this->present($request, $prepItem)]);
    }

    public function update(SavePrepItemRequest $request, Ingredient $prepItem): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueRecipesManage);
        $this->refuseIfNotPrep($prepItem);

        try {
            $prep = $this->save->update($prepItem, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($request, $prep)]);
    }

    public function destroy(Request $request, Ingredient $prepItem): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueRecipesManage);
        $this->refuseIfNotPrep($prepItem);

        try {
            $this->delete->handle($prepItem, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    public function history(Request $request, Ingredient $prepItem, RecipeQuantity $quantities): JsonResponse
    {
        $this->ensureCanRead($request);
        $this->refuseIfNotPrep($prepItem);

        return response()->json(['data' => PrepItemHistory::build($prepItem, $quantities)]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Ingredient $prep): array
    {
        $prep->loadMissing(['prepRecipeLines.ingredient.altUnits']);
        $usage = PrepUsage::of($prep);
        $resource = new PrepItemResource($prep);
        $resource->usedBy = [
            'product_recipes' => $usage->productRecipes,
            'addon_lines' => $usage->addonLines,
            'prep_recipes' => $usage->prepRecipes,
        ];

        return $resource->resolve($request);
    }

    private function ensureCanRead(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! ($user->can(MerchantPermission::CatalogueView->value) || $user->can(MerchantPermission::InventoryView->value))) {
            abort(403);
        }
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /** Tenant + kind: another company's row, or a plain ingredient, is a 404 here. */
    private function refuseIfNotPrep(Ingredient $ingredient): void
    {
        if ((int) $ingredient->company_id !== $this->tenant->requiredId() || ! $ingredient->isPrep()) {
            abort(404);
        }
    }
}

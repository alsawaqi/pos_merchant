<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\SetAllergensAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Support\Catalogue\Allergens;
use App\Support\Catalogue\AllergenSync;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\RecipeEditGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * LAUNCH costs & allergens add-on (tester call 3) — the allergen ticks.
 *
 *   GET /api/allergens                            the 14 codes (EN / AR)
 *   PUT /api/ingredients/{uuid}/allergens         {allergens: [codes]}
 *       an ingredient: inventory.manage; a prep item: "Edit recipes"
 *   GET /api/products/{uuid}/allergens            contains / may contain,
 *       what was worked out and the product's own ticks
 *   PUT /api/products/{uuid}/allergens            {contains, may_contain}
 *       a menu product: catalogue.manage; a physical item: inventory.manage
 *
 * Reads: catalogue.view or inventory.view. Every row is the merchant's own
 * (another merchant's uuid is a 404).
 */
class AllergensController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetAllergensAction $set,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanRead($request);

        return response()->json(['data' => Allergens::catalogue()]);
    }

    public function updateIngredient(Request $request, Ingredient $ingredient): JsonResponse
    {
        if ((int) $ingredient->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
        if ($ingredient->is_prep) {
            RecipeEditGate::ensure($request->user());
        } else {
            $this->ensure($request, MerchantPermission::InventoryManage);
        }
        $data = $request->validate([
            'allergens' => ['present', 'array', 'max:14'],
            'allergens.*' => ['string', Rule::in(Allergens::CODES)],
        ]);

        $saved = $this->set->ingredient($ingredient, $data['allergens'], $request->user());

        return response()->json(['data' => [
            'allergens' => $saved,
            // A prep item also brings what its recipe brings.
            'allergens_all' => AllergenSync::graph($this->tenant->requiredId())->ingredient((int) $ingredient->id),
        ]]);
    }

    public function showProduct(Request $request, Product $product): JsonResponse
    {
        $this->ensureCanRead($request);
        if ((int) $product->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        return response()->json(['data' => self::present((int) $product->company_id, (int) $product->id)]);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        if ((int) $product->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
        $this->ensure($request, $product->is_internal ? MerchantPermission::InventoryManage : MerchantPermission::CatalogueManage);
        $data = $request->validate([
            'contains' => ['present', 'array', 'max:14'],
            'contains.*' => ['string', Rule::in(Allergens::CODES)],
            'may_contain' => ['present', 'array', 'max:14'],
            'may_contain.*' => ['string', Rule::in(Allergens::CODES)],
        ]);

        $this->set->product($product, $data['contains'], $data['may_contain'], $request->user());

        return response()->json(['data' => self::present((int) $product->company_id, (int) $product->id)]);
    }

    /**
     * The product page's block:
     *   contains      everything it contains (ticked + worked out)
     *   may_contain   "may contain", never repeating `contains`
     *   derived       worked out from the recipe, prep items, components or
     *                 combo items — locked: the merchant cannot untick these
     *   own_contains / own_may_contain   the ticks set by hand
     *
     * @return array{contains: list<string>, may_contain: list<string>, derived: list<string>, own_contains: list<string>, own_may_contain: list<string>}
     */
    public static function present(int $companyId, int $productId): array
    {
        $graph = AllergenSync::graph($companyId);
        $of = $graph->product($productId);
        $own = $graph->ownProduct($productId);

        return [
            'contains' => $of['contains'],
            'may_contain' => $of['may_contain'],
            'derived' => $graph->derived($productId),
            'own_contains' => $own['contains'],
            'own_may_contain' => $own['may_contain'],
        ];
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
}

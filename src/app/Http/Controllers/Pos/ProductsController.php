<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateAddOnGroupAction;
use App\Actions\Pos\Catalogue\CreateProductAction;
use App\Actions\Pos\Catalogue\DeleteProductAction;
use App\Actions\Pos\Catalogue\ImportProductsAction;
use App\Actions\Pos\Catalogue\SyncProductAddOnGroupsAction;
use App\Actions\Pos\Catalogue\SyncProductBranchesAction;
use App\Actions\Pos\Catalogue\UpdateProductAction;
use App\Actions\Pos\Catalogue\UpdateProductComponentsAction;
use App\Actions\Pos\Catalogue\UpdateProductRecipeAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\CreateAddOnGroupRequest;
use App\Http\Requests\Pos\Catalogue\CreateProductRequest;
use App\Http\Requests\Pos\Catalogue\ImportProductsRequest;
use App\Http\Requests\Pos\Catalogue\SyncProductAddOnGroupsRequest;
use App\Http\Requests\Pos\Catalogue\SyncProductBranchesRequest;
use App\Http\Requests\Pos\Catalogue\UpdateProductComponentsRequest;
use App\Http\Requests\Pos\Catalogue\UpdateProductRecipeRequest;
use App\Http\Requests\Pos\Catalogue\UpdateProductRequest;
use App\Http\Resources\Pos\Catalogue\AddOnGroupResource;
use App\Http\Resources\Pos\Catalogue\ProductResource;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 *   GET    /api/products                     → list (?category=uuid, ?search, ?page, ?per_page)
 *   POST   /api/products                     → create
 *   PATCH  /api/products/{product:uuid}      → update
 *   DELETE /api/products/{product:uuid}      → soft delete
 *
 * Read gated on CatalogueView; mutations on CatalogueManage.
 * Tenant-scoped via MerchantTenantContext.
 *
 * Index is SERVER-PAGINATED (v2 #12 — the catalogue is the one merchant list
 * that grows large) and returns the standard {data, meta} envelope. Optional
 * ?category={uuid} narrows to one category; ?search matches name / name_ar
 * (case-insensitive); ?per_page clamps 1..200 (default 50).
 */
class ProductsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateProductAction $create,
        private readonly ImportProductsAction $importProducts,
        private readonly UpdateProductAction $update,
        private readonly DeleteProductAction $delete,
        private readonly SyncProductAddOnGroupsAction $syncAddOnGroups,
        private readonly UpdateProductRecipeAction $updateRecipe,
        private readonly UpdateProductComponentsAction $updateComponentsAction,
        private readonly SyncProductBranchesAction $syncBranches,
        private readonly CreateAddOnGroupAction $createAddOnGroup,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $companyId = $this->tenant->requiredId();

        $query = Product::query()
            ->where('company_id', $companyId)
            // Phase 4.9 — eager-load the product-specific add-on
            // groups so the edit modal's picker can pre-populate
            // without an extra round-trip. Globals are NOT included
            // here (they apply via the resolver, not the pivot).
            // Phase 5b — recipeLines + ingredient so the cost +
            // has_recipe + edit-modal pre-populate without extra
            // round-trips.
            // P-G2 — components + their product so the Physical items
            // section pre-populates without an extra round-trip.
            ->with(['category', 'addOnGroups', 'recipeLines.ingredient', ...$this->branchProductsEager($request), 'components.component']);

        // Optional ?category=<uuid> filter. Unknown / cross-
        // tenant uuid silently yields zero results (no leak).
        $categoryUuid = (string) $request->query('category', '');
        if ($categoryUuid !== '') {
            $categoryId = ProductCategory::query()
                ->where('uuid', $categoryUuid)
                ->where('company_id', $companyId)
                ->value('id');
            // -1 sentinel guarantees zero results when the
            // uuid was bogus or cross-tenant.
            $query->where('category_id', $categoryId ?? -1);
        }

        // v2 #12 — optional case-insensitive text search over name + name_ar.
        if ($request->filled('search')) {
            $like = '%'.strtolower(trim((string) $request->query('search'))).'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name_ar) LIKE ?', [$like]);
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return ProductResource::collection(
            $query
                ->orderBy('category_id')
                ->orderBy('display_order')
                ->orderBy('name')
                ->paginate($perPage),
        );
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $product = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->load(['category', 'addOnGroups', 'recipeLines.ingredient']);

        return response()->json([
            'data' => (new ProductResource($product))->resolve($request),
        ], 201);
    }

    /**
     * POST /api/products/import
     *
     * Bulk-create products from an uploaded CSV. Best-effort, row by row:
     * valid rows are created (each via CreateProductAction → audit + tenant
     * checks), invalid rows reported with their errors. Returns a per-row
     * summary {total, created, failed, rows[]}.
     */
    public function import(ImportProductsRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        $summary = $this->importProducts->handle(
            (string) $request->file('file')->get(),
            $request->user(),
        );

        return response()->json(['data' => $summary]);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        try {
            $updated = $this->update->handle($product, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['category', 'addOnGroups', 'recipeLines.ingredient', ...$this->branchProductsEager($request)]);

        return ProductResource::make($updated);
    }

    /**
     * PUT /api/products/{product:uuid}/components
     *
     * P-G2 — idempotent full-replace of the product's physical-item
     * components (coffee = 1 x cup 12oz + 1 x lid). Components must be
     * unit-mode products of the same company; pos_api consumes them from
     * branch unit stock at order.pay.
     */
    public function updateComponents(UpdateProductComponentsRequest $request, Product $product): ProductResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        try {
            $updated = $this->updateComponentsAction->handle(
                $product,
                $request->validated()['lines'] ?? [],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['category', 'addOnGroups', 'recipeLines.ingredient', ...$this->branchProductsEager($request), 'components.component']);

        return ProductResource::make($updated);
    }

    /**
     * GET /api/products/component-options
     *
     * P-G2 — the slim picker source for the Physical items section:
     * every unit-mode product of the company (internal items first —
     * cups/lids are the typical components). Deliberately tiny: the
     * full index resource is far too heavy for a dropdown.
     */
    public function componentOptions(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $options = Product::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('stock_mode', 'unit')
            ->orderByDesc('is_internal')
            ->orderBy('name')
            ->limit(500)
            ->get(['uuid', 'name', 'name_ar', 'is_internal'])
            ->map(static fn (Product $p): array => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'name_ar' => $p->name_ar,
                'is_internal' => (bool) $p->is_internal,
            ]);

        return response()->json(['data' => $options]);
    }

    /**
     * GET /api/products/addon-link-options
     *
     * P-G3 — the slim picker source for product-as-add-on: every
     * sellable (non-internal) product of the company. The add-on editor
     * links one of these so the option consumes its real stock.
     */
    public function addonLinkOptions(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $options = Product::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('is_internal', false)
            ->orderBy('name')
            ->limit(500)
            ->get(['uuid', 'name', 'name_ar', 'stock_mode'])
            ->map(static fn (Product $p): array => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'name_ar' => $p->name_ar,
                'stock_mode' => $p->stock_mode,
            ]);

        return response()->json(['data' => $options]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        $this->delete->handle($product, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * PUT /api/products/{product:uuid}/recipe
     *
     * Phase 5b — atomically replace the product's recipe.
     * Caller PUTs the full desired set of lines. Empty array =
     * "no recipe / no inventory deduction on sale". The Action
     * snapshots the pre-edit recipe to a version row, then
     * wipes + re-inserts the new lines, all in one transaction.
     * No-op when the recipe is identical to disk.
     */
    public function updateRecipe(UpdateProductRecipeRequest $request, Product $product): ProductResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        try {
            $updated = $this->updateRecipe->handle(
                $product,
                $request->validated()['lines'] ?? [],
                $request->user(),
                $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['category', 'addOnGroups', 'recipeLines.ingredient', ...$this->branchProductsEager($request)]);

        return ProductResource::make($updated);
    }

    /**
     * PUT /api/products/{product:uuid}/addon-groups
     *
     * Phase 4.9 — idempotent sync of product-specific add-on
     * group attachments. Caller passes the full desired list of
     * group uuids; the Action attaches what's missing, detaches
     * what's no longer wanted, and writes ONE audit row for the
     * delta (rather than N attach/detach rows).
     */
    public function syncAddOnGroups(SyncProductAddOnGroupsRequest $request, Product $product): JsonResponse|AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        try {
            $groups = $this->syncAddOnGroups->handle(
                $product,
                $request->validated()['group_uuids'] ?? [],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return AddOnGroupResource::collection(collect($groups));
    }

    /**
     * PUT /api/products/{product:uuid}/branches
     *
     * Replace the product's per-branch availability + unit stock (which
     * branches sell it + how many units each holds). Empty set = available
     * at every branch (the device-config default).
     */
    public function syncBranches(SyncProductBranchesRequest $request, Product $product): ProductResource|JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);
        // P-G5 — this is a FULL-REPLACE of the per-branch set: a scoped
        // user submitting it would silently delete other branches' rows,
        // so branch assignment stays an all-branches (HQ) operation.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'Branch availability is managed by accounts with access to all branches.');

        try {
            $this->syncBranches->handle(
                $product,
                $request->validated()['branches'] ?? [],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->load(['category', 'addOnGroups', 'recipeLines.ingredient', ...$this->branchProductsEager($request)]);

        return ProductResource::make($product);
    }

    /**
     * GET /api/products/{product:uuid}/addon-groups
     *
     * The add-on groups PRIVATELY OWNED by this product (v2 #6) — the
     * "add-ons unique to this product" editor list, with their options.
     * Shared/global groups are managed from the Add-ons settings tab.
     */
    public function addonGroups(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfNotInTenant($product);

        $groups = AddOnGroup::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('owner_product_id', $product->id)
            ->with(['addOns' => function ($q): void {
                $q->orderBy('display_order')->orderBy('name');
            }])
            // P-G3 — show what each option sells.
            ->with('addOns.linkedProduct')
            ->withCount('addOns')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return AddOnGroupResource::collection($groups);
    }

    /**
     * POST /api/products/{product:uuid}/addon-groups
     *
     * Create an add-on group PRIVATELY OWNED by this product (v2 #6). It is
     * never global, auto-attached to this product, and hidden from the shared
     * Add-ons list. Options are then added via the addons endpoints.
     */
    public function createAddonGroup(CreateAddOnGroupRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        $group = $this->createAddOnGroup->handle(
            array_merge($request->validated(), ['owner_product_id' => $product->id]),
            $request->user(),
        );
        $group->loadCount('addOns')->load('addOns');

        return response()->json([
            'data' => (new AddOnGroupResource($group))->resolve($request),
        ], 201);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    /**
     * P-G5 — the ProductResource inlines a per-branch {is_available,
     * stock_qty} row for every loaded pos_branch_product. A
     * branch-restricted user must only see their own branches'
     * inventory state, so constrain the eager-load to their scope (the
     * same protection ProductStockController + BranchesController@products
     * already apply). Unrestricted users load every branch as before.
     *
     * Returns an eager-load fragment to SPREAD into a with()/load()
     * array (`...$this->branchProductsEager($request)`): the plain
     * relation for unrestricted users, a scoped closure otherwise.
     *
     * @return array<int|string, \Closure|string>
     */
    private function branchProductsEager(Request $request): array
    {
        $allowed = $request->user()?->allowedBranchIds();
        if ($allowed === null) {
            return ['branchProducts'];
        }

        return ['branchProducts' => static function ($q) use ($allowed): void {
            $q->whereIn('branch_id', $allowed);
        }];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateProductAction;
use App\Actions\Pos\Catalogue\DeleteProductAction;
use App\Actions\Pos\Catalogue\SyncProductAddOnGroupsAction;
use App\Actions\Pos\Catalogue\UpdateProductAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\CreateProductRequest;
use App\Http\Requests\Pos\Catalogue\SyncProductAddOnGroupsRequest;
use App\Http\Requests\Pos\Catalogue\UpdateProductRequest;
use App\Http\Resources\Pos\Catalogue\AddOnGroupResource;
use App\Http\Resources\Pos\Catalogue\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 *   GET    /api/products                     → list (?category=uuid filter)
 *   POST   /api/products                     → create
 *   PATCH  /api/products/{product:uuid}      → update
 *   DELETE /api/products/{product:uuid}      → soft delete
 *
 * Read gated on CatalogueView; mutations on CatalogueManage.
 * Tenant-scoped via MerchantTenantContext.
 *
 * Index supports an optional ?category={uuid} filter for the
 * UI's category-side picker — the merchant clicks a category
 * tab and we list only its products instead of a flat
 * megadump. Without the filter we return everything ordered
 * by category then name.
 */
class ProductsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateProductAction $create,
        private readonly UpdateProductAction $update,
        private readonly DeleteProductAction $delete,
        private readonly SyncProductAddOnGroupsAction $syncAddOnGroups,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $companyId = $this->tenant->requiredId();

        $query = Product::query()
            ->where('company_id', $companyId)
            ->with('category');

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

        $products = $query
            ->orderBy('category_id')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $product = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->load('category');

        return response()->json([
            'data' => (new ProductResource($product))->resolve($request),
        ], 201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        try {
            $updated = $this->update->handle($product, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('category');

        return ProductResource::make($updated);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($product);

        $this->delete->handle($product, $request->user());

        return response()->json(['data' => null], 204);
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
    public function syncAddOnGroups(SyncProductAddOnGroupsRequest $request, Product $product): JsonResponse | AnonymousResourceCollection
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
}

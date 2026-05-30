<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateCategoryAction;
use App\Actions\Pos\Catalogue\DeleteCategoryAction;
use App\Actions\Pos\Catalogue\UpdateCategoryAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\CreateCategoryRequest;
use App\Http\Requests\Pos\Catalogue\UpdateCategoryRequest;
use App\Http\Resources\Pos\Catalogue\CategoryResource;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Manage product categories.
 *
 *   GET    /api/categories                  → list
 *   POST   /api/categories                  → create
 *   PATCH  /api/categories/{category:uuid}  → update
 *   DELETE /api/categories/{category:uuid}  → delete (refuses
 *                                              if has products)
 *
 * Read gated on CatalogueView; mutations on CatalogueManage.
 * Tenant-scoped via MerchantTenantContext.
 */
class CategoriesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateCategoryAction $create,
        private readonly UpdateCategoryAction $update,
        private readonly DeleteCategoryAction $delete,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $categories = ProductCategory::query()
            ->where('company_id', $this->tenant->requiredId())
            ->withCount(['products', 'subcategories'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        $category = $this->create->handle($request->validated(), $request->user());

        return response()->json([
            'data' => (new CategoryResource($category))->resolve($request),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, ProductCategory $category): CategoryResource
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($category);

        $updated = $this->update->handle($category, $request->validated(), $request->user());

        return CategoryResource::make($updated);
    }

    public function destroy(Request $request, ProductCategory $category): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($category);

        try {
            $this->delete->handle($category, $request->user());
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

    private function refuseIfNotInTenant(ProductCategory $category): void
    {
        if ((int) $category->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

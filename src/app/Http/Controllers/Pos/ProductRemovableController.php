<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\SyncRemovableIngredientsAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\SyncRemovableIngredientsRequest;
use App\Models\Product;
use App\Support\Catalogue\RemovableIngredients;
use App\Support\MerchantTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * LAUNCH review add-on (D12) — "Can be removed" on a product's recipe lines.
 *
 *   GET /api/products/{product:uuid}/removable   (catalogue.view)
 *   PUT /api/products/{product:uuid}/removable   (catalogue.manage)
 *       { lines: [{ ingredient_uuid, label?, label_ar? }] }
 *
 * Catalogue permission, not "Edit recipes": no amount changes, only the
 * product's own Remove list ({@see RemovableIngredients}). Tenant-scoped;
 * physical items are 404 (they have no recipe step).
 */
class ProductRemovableController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SyncRemovableIngredientsAction $sync,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfForeign($product);

        return response()->json(['data' => RemovableIngredients::state($product)]);
    }

    public function update(SyncRemovableIngredientsRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfForeign($product);

        try {
            $state = $this->sync->handle($product, array_values($request->validated()['lines'] ?? []), $request->user());
        } catch (QueryException|HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $state]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfForeign(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId() || $product->is_internal) {
            abort(404);
        }
    }
}

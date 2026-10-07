<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\SaveComboAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\SaveComboRequest;
use App\Http\Resources\Pos\Catalogue\ProductResource;
use App\Models\Product;
use App\Support\MerchantTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * LAUNCH-P4 B2 — the combos editor (owner decision 7).
 *
 *   POST /api/combos                 → create a combo with its lines
 *   GET  /api/combos/{product:uuid}  → one combo with its lines
 *   PUT  /api/combos/{product:uuid}  → save the whole combo (lines keep ids)
 *
 * LAUNCH combo add-on — a combo is a price plus lines (included items with
 * upgrades, choices from a category).
 *
 * A combo is listed, deleted and switched sold out like any product (the
 * catalogue endpoints). Read gated on catalogue.view, writes on
 * catalogue.manage; a branch rule in the payload is HQ-only.
 */
class CombosController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SaveComboAction $save,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfNotCombo($product);

        return response()->json(['data' => $this->resource($request, $product)]);
    }

    public function store(SaveComboRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        return $this->persist($request, null, 201);
    }

    public function update(SaveComboRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotCombo($product);

        return $this->persist($request, $product, 200);
    }

    private function persist(SaveComboRequest $request, ?Product $combo, int $status): JsonResponse
    {
        try {
            $saved = $this->save->handle($combo, $request->validated(), $request->user());
        } catch (QueryException|HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->resource($request, $saved)], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Request $request, Product $combo): array
    {
        $allowed = $request->user()?->allowedBranchIds();
        $combo->load([
            'category',
            'comboLines',
            'deliveryPrices.deliveryProvider',
            'branchProducts' => static function ($q) use ($allowed): void {
                if ($allowed !== null) {
                    $q->whereIn('branch_id', $allowed);
                }
            },
        ]);

        return (new ProductResource($combo))->resolve($request);
    }

    private function refuseIfNotCombo(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId() || ! $product->isCombo()) {
            abort(404);
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

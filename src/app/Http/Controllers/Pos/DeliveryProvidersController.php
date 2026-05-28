<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\DeliveryProviders\CreateDeliveryProviderAction;
use App\Actions\Pos\DeliveryProviders\DeleteDeliveryProviderAction;
use App\Actions\Pos\DeliveryProviders\RemoveProductDeliveryPriceAction;
use App\Actions\Pos\DeliveryProviders\SetProductDeliveryPriceAction;
use App\Actions\Pos\DeliveryProviders\UpdateDeliveryProviderAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\DeliveryProviders\CreateDeliveryProviderRequest;
use App\Http\Requests\Pos\DeliveryProviders\SetProductDeliveryPriceRequest;
use App\Http\Requests\Pos\DeliveryProviders\UpdateDeliveryProviderRequest;
use App\Http\Resources\Pos\DeliveryProviders\DeliveryProviderResource;
use App\Http\Resources\Pos\DeliveryProviders\ProductDeliveryPriceResource;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 6c — delivery providers CRUD + per-product price overrides.
 *
 *   GET    /api/delivery-providers
 *   POST   /api/delivery-providers
 *   PATCH  /api/delivery-providers/{provider:uuid}
 *   DELETE /api/delivery-providers/{provider:uuid}
 *
 *   GET    /api/products/{product:uuid}/delivery-prices
 *   PUT    /api/products/{product:uuid}/delivery-prices/{provider:uuid}
 *   DELETE /api/products/{product:uuid}/delivery-prices/{provider:uuid}
 *
 * Permission gating:
 *   - CatalogueView   for GET endpoints
 *   - CatalogueManage for every write
 *
 * Folded under the existing Catalogue permissions because
 * setting provider prices IS product pricing — same risk
 * class. See Phase 6c-3 commit for the rationale.
 */
class DeliveryProvidersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateDeliveryProviderAction $createProvider,
        private readonly UpdateDeliveryProviderAction $updateProvider,
        private readonly DeleteDeliveryProviderAction $deleteProvider,
        private readonly SetProductDeliveryPriceAction $setPrice,
        private readonly RemoveProductDeliveryPriceAction $removePrice,
    ) {}

    // =================== PROVIDER CRUD ===================

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $providers = DeliveryProvider::query()
            ->where('company_id', $this->tenant->requiredId())
            ->withCount('prices')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return DeliveryProviderResource::collection($providers);
    }

    public function store(CreateDeliveryProviderRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $provider = $this->createProvider->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new DeliveryProviderResource($provider))->resolve($request),
        ], 201);
    }

    public function update(UpdateDeliveryProviderRequest $request, DeliveryProvider $provider): DeliveryProviderResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfProviderNotInTenant($provider);

        try {
            $updated = $this->updateProvider->handle($provider, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DeliveryProviderResource::make($updated);
    }

    public function destroy(Request $request, DeliveryProvider $provider): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfProviderNotInTenant($provider);

        $this->deleteProvider->handle($provider, $request->user());

        return response()->json(['data' => null], 204);
    }

    // =================== PER-PRODUCT PRICE OVERRIDES ===================

    public function listPrices(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfProductNotInTenant($product);

        $product->load(['deliveryPrices.deliveryProvider']);

        return ProductDeliveryPriceResource::collection($product->deliveryPrices);
    }

    public function setPrice(
        SetProductDeliveryPriceRequest $request,
        Product $product,
        DeliveryProvider $provider,
    ): JsonResponse {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfProductNotInTenant($product);
        // Provider cross-tenant check happens in the Action
        // (it throws RuntimeException -> 422 below). We
        // intentionally don't 404 the URL here because the
        // {provider:uuid} binding resolved fine — letting the
        // controller relay a 422 is more informative.

        try {
            $row = $this->setPrice->handle(
                $product,
                $provider,
                (string) $request->validated()['price'],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $row->load('deliveryProvider');

        return response()->json([
            'data' => (new ProductDeliveryPriceResource($row))->resolve($request),
        ], 201);
    }

    public function removePrice(
        Request $request,
        Product $product,
        DeliveryProvider $provider,
    ): JsonResponse {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfProductNotInTenant($product);

        try {
            $this->removePrice->handle($product, $provider, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    // =================== HELPERS ===================

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfProviderNotInTenant(DeliveryProvider $provider): void
    {
        if ((int) $provider->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfProductNotInTenant(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

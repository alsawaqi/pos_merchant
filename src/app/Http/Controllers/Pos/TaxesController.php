<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Taxes\CreateTaxAction;
use App\Actions\Pos\Taxes\DeleteTaxAction;
use App\Actions\Pos\Taxes\UpdateTaxAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Taxes\CreateTaxRequest;
use App\Http\Requests\Pos\Taxes\UpdateTaxRequest;
use App\Http\Resources\Pos\Taxes\TaxResource;
use App\Models\Tax;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Company-level taxes CRUD (merchant settings).
 *
 *   GET    /api/taxes
 *   POST   /api/taxes
 *   PATCH  /api/taxes/{tax:uuid}
 *   DELETE /api/taxes/{tax:uuid}
 *
 * Permission gating reuses the Catalogue keys (CatalogueView for read,
 * CatalogueManage for writes) — same precedent as DeliveryProviders: a
 * company-wide pricing setting that feeds the POS bill, same risk class as
 * product pricing. The Main POS fetches the active set via /device/config.
 */
class TaxesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateTaxAction $createTax,
        private readonly UpdateTaxAction $updateTax,
        private readonly DeleteTaxAction $deleteTax,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        $taxes = Tax::query()
            ->where('company_id', $this->tenant->requiredId())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return TaxResource::collection($taxes);
    }

    public function store(CreateTaxRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $tax = $this->createTax->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new TaxResource($tax))->resolve($request),
        ], 201);
    }

    public function update(UpdateTaxRequest $request, Tax $tax): TaxResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($tax);

        try {
            $updated = $this->updateTax->handle($tax, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return TaxResource::make($updated);
    }

    public function destroy(Request $request, Tax $tax): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($tax);

        $this->deleteTax->handle($tax, $request->user());

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Tax $tax): void
    {
        if ((int) $tax->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetPricesIncludeVatAction;
use App\Actions\Pos\Taxes\AddStandardVatAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdatePricesIncludeVatRequest;
use App\Http\Resources\Pos\Taxes\TaxResource;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Tax;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * LAUNCH-P4 B1 — the VAT settings on the Taxes page (owner decision 1).
 *
 *   GET  /api/settings/tax                     → VAT registration (read-only,
 *        from the company record pos_admin keeps), the "menu prices include
 *        VAT" switch (default true) and whether an active tax row exists
 *   PUT  /api/settings/tax/prices-include-vat  → set the switch
 *   POST /api/settings/tax/add-vat             → one-click "VAT 5%" for a
 *        registered company with no active tax row
 *
 * Read gated by catalogue.view, writes by catalogue.manage — the Taxes page
 * gates.
 */
class TaxSettingsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetPricesIncludeVatAction $setPricesIncludeVat,
        private readonly AddStandardVatAction $addStandardVat,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        return response()->json(['data' => $this->payload()]);
    }

    public function updatePricesIncludeVat(UpdatePricesIncludeVatRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        $this->setPricesIncludeVat->handle($request->boolean('prices_include_vat'), $request->user());

        return response()->json(['data' => $this->payload()]);
    }

    public function addVat(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        try {
            $tax = $this->addStandardVat->handle($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->payload() + ['tax' => (new TaxResource($tax))->resolve($request)],
        ], 201);
    }

    /**
     * @return array{vat_registered: bool, vat_registered_at: string|null, vat_number: string|null, prices_include_vat: bool, has_active_tax: bool, needs_vat_row: bool}
     */
    private function payload(): array
    {
        $companyId = $this->tenant->requiredId();
        $company = Company::query()->findOrFail($companyId);
        $registered = $company->vat_registered_at !== null;
        $hasActiveTax = Tax::query()->where('company_id', $companyId)->where('is_active', true)->exists();

        return [
            'vat_registered' => $registered,
            'vat_registered_at' => $company->vat_registered_at?->format('Y-m-d'),
            'vat_number' => $company->vat_number !== null && $company->vat_number !== '' ? (string) $company->vat_number : null,
            'prices_include_vat' => CompanySetting::boolFor($companyId, CompanySetting::KEY_PRICES_INCLUDE_VAT, true),
            'has_active_tax' => $hasActiveTax,
            // Registered but nothing charged: the page warns and offers
            // the one-click "Add VAT 5%".
            'needs_vat_row' => $registered && ! $hasActiveTax,
        ];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetPurchaseTaxRecoverableAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdatePurchaseTaxRecoverableRequest;
use App\Models\CompanySetting;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PT — the purchase_tax_recoverable company setting (lives on the Taxes page).
 *
 *   GET /api/settings/purchase-tax-recoverable  → the current flag (default false)
 *   PUT /api/settings/purchase-tax-recoverable  → set it
 *
 * Read gated by catalogue.view, write by catalogue.manage — the same gates as
 * the Taxes screen this toggle sits on.
 */
class PurchaseTaxRecoverableSettingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetPurchaseTaxRecoverableAction $setAction,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);

        return response()->json([
            'data' => ['purchase_tax_recoverable' => $this->current()],
        ]);
    }

    public function update(UpdatePurchaseTaxRecoverableRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        $saved = $this->setAction->handle(
            $request->boolean('purchase_tax_recoverable'),
            $request->user(),
        );

        return response()->json([
            'data' => ['purchase_tax_recoverable' => $saved],
        ]);
    }

    private function current(): bool
    {
        $value = CompanySetting::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('key', CompanySetting::KEY_PURCHASE_TAX_RECOVERABLE)
            ->value('value');

        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return $decoded === true || $decoded === 1 || $decoded === '1' || $decoded === 'true';
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}

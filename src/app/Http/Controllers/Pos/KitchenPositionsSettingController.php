<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetKitchenPositionsAction;
use App\Enums\MerchantPermission;
use App\Enums\StaffPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdateKitchenPositionsRequest;
use App\Models\CompanySetting;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P-G1 — device Kitchen-section access policy (who may open the Kitchen
 * production screen on the POS device and run cooked-product batches).
 *
 *   GET /api/settings/kitchen-positions → the allowed positions + the full
 *                                         position catalogue (for the editor)
 *   PUT /api/settings/kitchen-positions → set the allowed positions
 *
 * Both gated on orders.cancel — the precedent set by the sibling position
 * policies this card rides with on the same settings page
 * (order-cancellation, manager-approval, reports). Tenant-scoped via
 * MerchantTenantContext. The default (no row yet) is managers-only,
 * matching what pos_api emits to the device.
 */
class KitchenPositionsSettingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetKitchenPositionsAction $setPositions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request);

        return response()->json([
            'data' => [
                'positions' => $this->currentPositions(),
                'available_positions' => UpdateKitchenPositionsRequest::selectablePositions(),
            ],
        ]);
    }

    public function update(UpdateKitchenPositionsRequest $request): JsonResponse
    {
        $this->ensure($request);

        /** @var list<string> $positions */
        $positions = $request->validated('positions');
        $this->setPositions->handle($positions, $request->user());

        return response()->json([
            'data' => [
                'positions' => $this->currentPositions(),
                'available_positions' => UpdateKitchenPositionsRequest::selectablePositions(),
            ],
        ]);
    }

    /**
     * The OTHER positions the merchant has ticked for kitchen access. The
     * 'kitchen' role is implicit (always allowed, enforced in pos_api) and never
     * shown here, so it is stripped. A row that exists with an empty list reads
     * back as [] (kitchen-role-only) — only the ABSENCE of a row falls back to a
     * default, and that default is now also [] (no managers-only).
     *
     * @return list<string>
     */
    private function currentPositions(): array
    {
        $setting = CompanySetting::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('key', CompanySetting::KEY_KITCHEN_POSITIONS)
            ->first();

        if ($setting === null) {
            return [];
        }

        $positions = is_array($setting->value) ? $setting->value : [];

        return array_values(array_filter(
            array_map('strval', $positions),
            static fn (string $p): bool => $p !== '' && $p !== StaffPosition::Kitchen->value,
        ));
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::OrdersCancel->value)) {
            abort(403);
        }
    }
}

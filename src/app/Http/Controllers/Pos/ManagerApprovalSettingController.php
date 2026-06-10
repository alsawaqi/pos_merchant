<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetManagerApprovalPositionsAction;
use App\Enums\MerchantPermission;
use App\Enums\StaffPosition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdateManagerApprovalPositionsRequest;
use App\Models\CompanySetting;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P-F1 — manager approval policy (the device's manager-PIN fallback).
 *
 *   GET /api/settings/manager-approval → the approved positions + the full
 *                                        position catalogue (for the editor)
 *   PUT /api/settings/manager-approval → set the approved positions
 *
 * Both gated on orders.cancel — the same gate as the order-cancellation
 * policy this rides with (both govern who may authorize money-reversing POS
 * actions). Tenant-scoped via MerchantTenantContext. The default (no row
 * yet) is managers-only, matching what pos_api emits to the device and
 * enforces on /device/auth/verify-manager-pin.
 */
class ManagerApprovalSettingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetManagerApprovalPositionsAction $setPositions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request);

        return response()->json([
            'data' => [
                'positions' => $this->currentPositions(),
                'available_positions' => StaffPosition::values(),
            ],
        ]);
    }

    public function update(UpdateManagerApprovalPositionsRequest $request): JsonResponse
    {
        $this->ensure($request);

        /** @var list<string> $positions */
        $positions = $request->validated('positions');
        $saved = $this->setPositions->handle($positions, $request->user());

        return response()->json([
            'data' => [
                'positions' => $saved,
                'available_positions' => StaffPosition::values(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function currentPositions(): array
    {
        $value = CompanySetting::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('key', CompanySetting::KEY_MANAGER_APPROVAL_POSITIONS)
            ->value('value');

        $positions = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($positions) || $positions === []) {
            return ['manager']; // safe default, mirrors pos_api/device fallback
        }

        return array_values(array_map('strval', $positions));
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::OrdersCancel->value)) {
            abort(403);
        }
    }
}

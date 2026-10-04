<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetPositionPermissionsAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdatePositionPermissionsRequest;
use App\Support\MerchantTenantContext;
use App\Support\PositionPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LAUNCH-P5 B1 — the staff permissions page (tick list per position).
 *
 *   GET /api/settings/staff-permissions → the resolved matrix, the defaults
 *                                         and the fixed positions / actions
 *   PUT /api/settings/staff-permissions → change ticks / limits
 *
 * Both gated on staff.permissions.manage (Super Admin + Manager by default).
 * It replaces the four position lists of Settings → Order cancellation, which
 * were gated on orders.cancel; the old lists are now written from this matrix.
 */
class StaffPermissionsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetPositionPermissionsAction $setPermissions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request);

        return response()->json([
            'data' => $this->payload(PositionPermissions::forCompany($this->tenant->requiredId())),
        ]);
    }

    public function update(UpdatePositionPermissionsRequest $request): JsonResponse
    {
        $this->ensure($request);

        $saved = $this->setPermissions->handle($request->changes(), $request->user());

        return response()->json(['data' => $this->payload($saved)]);
    }

    /**
     * @param  array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>  $matrix
     * @return array<string, mixed>
     */
    private function payload(array $matrix): array
    {
        return [
            'positions' => PositionPermissions::POSITIONS,
            'actions' => PositionPermissions::ACTIONS,
            'always_on' => PositionPermissions::ALWAYS_ON,
            'permissions' => $matrix,
            'defaults' => PositionPermissions::defaults(),
        ];
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::StaffPermissionsManage->value)) {
            abort(403);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B — re-open a closed shift (Additions §1.2: "Manager can
 * re-open a closed shift within the same business day to correct an
 * obvious mistake (audited)").
 *
 *   POST /api/shifts/{shift:uuid}/reopen
 *
 * Same-business-day only (the close's calendar date must be today);
 * re-opening clears the closing capture (counted/expected/variance)
 * so the next close recomputes from the full shift window. Gated on
 * orders.cancel — the same manager-grade money lever as voids.
 */
class ShiftsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function reopen(Request $request, Shift $shift): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::OrdersCancel->value)) {
            abort(403);
        }
        if ((int) $shift->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
        if ($shift->status !== ShiftStatus::Closed) {
            return response()->json(['message' => 'Only a closed shift can be re-opened.'], 422);
        }
        if ($shift->closed_at === null || ! $shift->closed_at->isToday()) {
            return response()->json([
                'message' => 'A shift can only be re-opened on the same business day it was closed.',
            ], 422);
        }

        $before = [
            'closed_at' => $shift->closed_at->toIso8601String(),
            'closing_cash' => (string) $shift->closing_cash,
            'expected_cash' => (string) $shift->expected_cash,
            'variance' => (string) $shift->variance,
        ];

        $shift->forceFill([
            'status' => ShiftStatus::Open->value,
            'closed_at' => null,
            'closing_cash' => null,
            'expected_cash' => null,
            'variance' => null,
        ])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'shift.reopened',
            actorUserId: $user->getKey(),
            companyId: $this->tenant->requiredId(),
            branchId: $shift->branch_id,
            auditableType: Shift::class,
            auditableId: $shift->id,
            oldValues: $before,
            newValues: ['status' => 'open'],
        ));

        return response()->json(['data' => ['uuid' => $shift->uuid, 'status' => 'open']]);
    }
}

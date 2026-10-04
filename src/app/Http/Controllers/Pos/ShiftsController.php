<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 *
 * LAUNCH-P5 B5 — "the same business day" is the MUSCAT day
 * (pos.business_timezone), not the UTC day: a shift closed at 01:30 Muscat
 * can be re-opened that morning. Re-opening also clears what pos_api
 * records at the close (closed_by, close device, pay-outs, late sales and
 * the needs-review flag); the next close recomputes them. The old values go
 * into the audit row.
 *
 * LAUNCH-P5 follow-up 1 — each re-open adds 1 to pos_shifts.reopen_count in
 * the same save. Devices build the fixed shift-close event id from
 * "shift-close:{shift_uuid}:{reopen_count}", so a re-opened shift can be
 * closed again with a new id.
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
        // One transaction with the shift row locked: the checks, the re-open,
        // the close-field reset, the reopen_count increment and the audit row
        // land together, and two re-opens at once cannot both count.
        $result = DB::transaction(function () use ($shift, $user): JsonResponse|int {
            /** @var Shift $locked */
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShiftStatus::Closed) {
                return response()->json(['message' => 'Only a closed shift can be re-opened.'], 422);
            }
            if ($locked->closed_at === null || ! BusinessTime::sameDay($locked->closed_at, Carbon::now())) {
                return response()->json([
                    'message' => 'A shift can only be re-opened on the same business day it was closed.',
                ], 422);
            }

            $before = [
                'closed_at' => $locked->closed_at->toIso8601String(),
                'closing_cash' => (string) $locked->closing_cash,
                'expected_cash' => (string) $locked->expected_cash,
                'variance' => (string) $locked->variance,
                'closed_by_staff_id' => $locked->getAttribute('closed_by_staff_id'),
                'close_device_id' => $locked->getAttribute('close_device_id'),
                'needs_review' => (bool) $locked->getAttribute('needs_review'),
                'late_sales_baisas' => (int) $locked->getAttribute('late_sales_baisas'),
                'payouts_baisas' => (int) $locked->getAttribute('payouts_baisas'),
                'reopen_count' => (int) $locked->getAttribute('reopen_count'),
            ];
            $reopenCount = (int) $locked->getAttribute('reopen_count') + 1;

            $locked->forceFill([
                'status' => ShiftStatus::Open->value,
                'closed_at' => null,
                'closing_cash' => null,
                'expected_cash' => null,
                'variance' => null,
                'closed_by_staff_id' => null,
                'close_device_id' => null,
                'needs_review' => false,
                'late_sales_baisas' => 0,
                'payouts_baisas' => 0,
                'reopen_count' => $reopenCount,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'shift.reopened',
                actorUserId: $user->getKey(),
                companyId: $this->tenant->requiredId(),
                branchId: $locked->branch_id,
                auditableType: Shift::class,
                auditableId: $locked->id,
                oldValues: $before,
                newValues: ['status' => 'open', 'reopen_count' => $reopenCount],
            ));

            return $reopenCount;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }
        $reopenCount = $result;

        return response()->json(['data' => ['uuid' => $shift->uuid, 'status' => 'open', 'reopen_count' => $reopenCount]]);
    }
}

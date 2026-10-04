<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Staff\EditAttendanceAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Staff\UpdateAttendanceRequest;
use App\Models\StaffAttendance;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;

/**
 * LAUNCH-P5 B4 — correct a clock-in / clock-out from the Hours report.
 *
 *   PATCH /api/attendance/{attendance:uuid}  {clock_in_at, clock_out_at, reason}
 *
 * Gated by staff.attendance.manage; tenant-checked (404) before the branch
 * scope (403, EnsureBranchScope + here); audited by the action.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly EditAttendanceAction $edit,
    ) {}

    public function update(UpdateAttendanceRequest $request, StaffAttendance $attendance): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::StaffAttendanceManage->value)) {
            abort(403);
        }
        if ((int) $attendance->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
        if (! $user->canAccessBranchId((int) $attendance->branch_id)) {
            abort(403, 'Your account is restricted to specific branches.');
        }

        $saved = $this->edit->handle($attendance, $request->clockIn(), $request->clockOut(), $request->reason(), $user);

        return response()->json(['data' => [
            'uuid' => $saved->uuid,
            'clock_in_local' => BusinessTime::local($saved->clock_in_at),
            'clock_out_local' => BusinessTime::local($saved->clock_out_at),
            'edit_reason' => $saved->edit_reason,
        ]]);
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 B4 — correct one clock-in / clock-out (e.g. add the clock-out a
 * person forgot). Times come as Muscat-local "Y-m-d H:i" and are stored in
 * UTC. The reason is required and kept on the row (edit_reason,
 * edited_by_user_id); every change is audited (`staff.attendance.edited`,
 * old and new times plus the reason). A save that changes no time still
 * records nothing.
 */
final readonly class EditAttendanceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(StaffAttendance $attendance, Carbon $clockIn, ?Carbon $clockOut, string $reason, User $actor): StaffAttendance
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $attendance->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($attendance, $clockIn, $clockOut, $reason, $actor, $companyId): StaffAttendance {
            $old = [
                'clock_in_at' => BusinessTime::local($attendance->clock_in_at),
                'clock_out_at' => BusinessTime::local($attendance->clock_out_at),
            ];
            $new = [
                'clock_in_at' => BusinessTime::local($clockIn),
                'clock_out_at' => BusinessTime::local($clockOut),
            ];
            if ($old === $new) {
                return $attendance;
            }

            $attendance->forceFill([
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'edited_by_user_id' => $actor->getKey(),
                'edit_reason' => $reason,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'staff.attendance.edited',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: (int) $attendance->branch_id,
                auditableType: StaffAttendance::class,
                auditableId: $attendance->id,
                oldValues: $old,
                newValues: $new,
                metadata: [
                    'reason' => $reason,
                    'staff_id' => (int) $attendance->staff_id,
                    'timezone' => BusinessTime::timezone(),
                ],
            ));

            return $attendance->fresh();
        });
    }
}

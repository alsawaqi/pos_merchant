<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StaffStatus;
use App\Models\PosStaff;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * End employment — soft-delete + status flip in a single
 * transaction. Stamps terminated_at so HR reports can answer
 * "who left this quarter?" without joining the audit log.
 *
 * Why two markers (deleted_at AND status=terminated):
 *   - deleted_at hides the row from default ::query() lookups
 *     (the device's staff-picker, the portal's roster) without
 *     us having to add a status whereNot everywhere.
 *   - status=terminated makes the lifecycle state explicit on
 *     the row even when fetched via withTrashed() — important
 *     for audit/forensic reads where the soft-delete flag alone
 *     would be ambiguous ("did the row vanish due to termination
 *     or a maintenance script?").
 *
 * Re-hire is a separate create call, not an undo of this. Audit
 * log preserves the lineage either way.
 */
final readonly class TerminatePosStaffAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(PosStaff $staff, User $actor): PosStaff
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $staff->company_id !== $companyId) {
            abort(404);
        }

        // Idempotent: a second terminate call on an already-
        // terminated row is a no-op (the soft-delete is already
        // there, the status is already set).
        if ($staff->status === StaffStatus::Terminated) {
            return $staff;
        }

        return DB::transaction(function () use ($staff, $actor, $companyId): PosStaff {
            $previousStatus = $staff->status?->value;

            $staff->status = StaffStatus::Terminated;
            $staff->terminated_at = now();
            $staff->save();
            $staff->delete(); // soft delete (SoftDeletes trait)

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'pos_staff.terminated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PosStaff::class,
                auditableId: $staff->id,
                oldValues: ['status' => $previousStatus],
                newValues: [
                    'status' => StaffStatus::Terminated->value,
                    'terminated_at' => $staff->terminated_at?->toIso8601String(),
                ],
            ));

            return $staff;
        });
    }
}

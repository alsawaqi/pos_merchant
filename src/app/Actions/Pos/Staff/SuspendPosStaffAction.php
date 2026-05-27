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
use RuntimeException;

/**
 * Suspend a staff member — temporary block. Their PIN stops
 * unlocking the device until reactivation. The row stays put
 * (no soft-delete), so re-instating later is a single status
 * flip with no PIN reset required.
 *
 * Refuses to suspend a terminated row (they're already
 * suspended permanently — re-hire first if needed).
 * Idempotent on already-suspended rows.
 */
final readonly class SuspendPosStaffAction
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

        if ($staff->status === StaffStatus::Terminated) {
            throw new RuntimeException(
                'Terminated staff cannot be suspended — re-hire them first.',
            );
        }

        if ($staff->status === StaffStatus::Suspended) {
            return $staff;
        }

        return DB::transaction(function () use ($staff, $actor, $companyId): PosStaff {
            $previous = $staff->status?->value;
            $staff->status = StaffStatus::Suspended;
            $staff->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'pos_staff.suspended',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PosStaff::class,
                auditableId: $staff->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => StaffStatus::Suspended->value],
            ));

            return $staff;
        });
    }
}

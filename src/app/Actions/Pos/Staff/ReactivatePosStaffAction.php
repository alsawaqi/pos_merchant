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
 * Flip a suspended row back to active. Idempotent on
 * already-active rows. Refuses terminated rows — those need
 * to go through CreatePosStaffAction (or a future re-hire
 * action) so a fresh PIN is minted and the audit trail shows
 * a deliberate re-onboarding rather than a covert revival.
 */
final readonly class ReactivatePosStaffAction
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
                'Terminated staff cannot be reactivated — re-hire them as a new record.',
            );
        }

        if ($staff->status === StaffStatus::Active) {
            return $staff;
        }

        return DB::transaction(function () use ($staff, $actor, $companyId): PosStaff {
            $previous = $staff->status?->value;
            $staff->status = StaffStatus::Active;
            $staff->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'pos_staff.reactivated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PosStaff::class,
                auditableId: $staff->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => StaffStatus::Active->value],
            ));

            return $staff;
        });
    }
}

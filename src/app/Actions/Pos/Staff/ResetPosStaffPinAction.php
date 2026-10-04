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
 * Mint a new 6-digit PIN, write its bcrypt hash to the row,
 * return the plaintext ONCE in the response envelope. Same
 * one-shot semantics as the create flow's PIN delivery.
 *
 * Refuses terminated rows — they shouldn't have an active PIN.
 *
 * Refuses to land a PIN that collides with another active or
 * suspended staff member at the same company (same uniqueness
 * rule the create action enforces).
 *
 * Audit event: `pos_staff.pin_reset`. The new PIN is NEVER
 * logged (not the plaintext, not even the hash) — credential
 * material must not leak into the audit trail.
 *
 * LAUNCH-P5 B2: minted by {@see MintStaffPinAction} — under a lock on the
 * company row (L3) and with a fresh offline approver verifier, so the old
 * PIN stops working for offline approvals as soon as devices refresh their
 * approver list. Gated by pos_staff.reset_pin (no longer pos_staff.update).
 */
final readonly class ResetPosStaffPinAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private MintStaffPinAction $mintPin,
    ) {}

    /**
     * @return array{staff: PosStaff, plaintext_pin: string}
     */
    public function handle(PosStaff $staff, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $staff->company_id !== $companyId) {
            abort(404);
        }

        if ($staff->status === StaffStatus::Terminated) {
            throw new RuntimeException(
                'Cannot reset the PIN of a terminated staff member.',
            );
        }

        return DB::transaction(function () use ($staff, $actor, $companyId): array {
            $minted = $this->mintPin->handle($companyId, excludeStaffId: $staff->id);

            $staff->pin_hash = $minted['hash'];
            $staff->save();
            // L7 — K in its own UPDATE, never in another statement's bindings.
            $this->mintPin->storeVerifier((int) $staff->id, $minted['verifier']);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'pos_staff.pin_reset',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PosStaff::class,
                auditableId: $staff->id,
                // Intentionally empty — nothing about the PIN
                // itself goes into the log. The presence of the
                // event row is the audit signal.
                newValues: [],
            ));

            return [
                'staff' => $staff,
                'plaintext_pin' => $minted['pin'],
            ];
        });
    }
}

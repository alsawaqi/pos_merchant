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
use Illuminate\Support\Facades\Hash;
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
 */
final readonly class ResetPosStaffPinAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
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
            [$pin, $hash] = $this->mintUniquePin($companyId, excludeStaffId: $staff->id);

            $staff->pin_hash = $hash;
            $staff->save();

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
                'plaintext_pin' => $pin,
            ];
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mintUniquePin(int $companyId, int $excludeStaffId): array
    {
        $existing = PosStaff::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $excludeStaffId)
            ->whereIn('status', [
                StaffStatus::Active->value,
                StaffStatus::Suspended->value,
            ])
            ->pluck('pin_hash');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            $collides = false;
            foreach ($existing as $hash) {
                if (Hash::check($candidate, $hash)) {
                    $collides = true;
                    break;
                }
            }

            if (! $collides) {
                return [$candidate, Hash::make($candidate)];
            }
        }

        throw new RuntimeException(
            'Could not generate a unique PIN after 10 attempts.',
        );
    }
}

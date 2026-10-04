<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StaffStatus;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hire a new POS staff member.
 *
 * Generates a 6-digit numeric PIN server-side, hashes it with
 * bcrypt, returns the plaintext ONCE in the response envelope.
 * The portal admin then shares it with the staff member out of
 * band — the device prompts for the PIN on first unlock.
 *
 * PIN uniqueness:
 *   Two staff at the same company must never share a PIN — the
 *   device's "type your PIN to clock in" UX needs an unambiguous
 *   lookup. We can't put a DB UNIQUE on pin_hash (bcrypt salt
 *   would mask collisions). Instead, after generating a PIN, we
 *   walk every active+suspended row at this company and use
 *   Hash::check to confirm the new PIN doesn't already exist.
 *   On collision we re-generate. With 1M PINs and < 100 staff
 *   per company, the first roll almost always wins; bounded
 *   retry loop caps at 10 attempts before we surface a
 *   RuntimeException — at which point the merchant has either
 *   exhausted the keyspace or our generator is broken.
 *
 * Branch gate:
 *   The chosen branch must belong to the actor's company. The
 *   FormRequest already validates this, but we re-check at the
 *   Action layer so any direct caller (job, future API client)
 *   can't dodge the check.
 *
 * Audit event: `pos_staff.created` — name + position + branch +
 * staff_code captured in new_values. PIN never logged (not even
 * its hash — credential material must not leak into audit).
 *
 * LAUNCH-P5 B2:
 *   - the PIN is minted by {@see MintStaffPinAction}: under a lock on the
 *     company row (L3), and with the offline approver verifier
 *     (pin_offline_key / _salt / _iterations) stored alongside the hash;
 *   - `branch_ids` (optional) are the other branches the person works at;
 *     the home branch is always included ({@see SyncStaffBranchesAction}).
 */
final readonly class CreatePosStaffAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private MintStaffPinAction $mintPin,
        private SyncStaffBranchesAction $syncBranches,
    ) {}

    /**
     * @param  array{name: string, branch_id: int, position: string, phone?: string|null, staff_code?: string|null, hired_at?: string|null, branch_ids?: list<int>|null}  $attributes
     * @return array{staff: PosStaff, plaintext_pin: string}
     */
    public function handle(array $attributes, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($attributes, $actor, $companyId): array {
            // Belt-and-braces — controller validator already
            // checked this, but a direct Action call must still
            // be safe.
            $branch = Branch::query()
                ->where('id', $attributes['branch_id'])
                ->where('company_id', $companyId)
                ->first();
            if ($branch === null) {
                throw new RuntimeException(
                    'The selected branch does not belong to your company.',
                );
            }

            $minted = $this->mintPin->handle($companyId);

            $staff = new PosStaff([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'name' => $attributes['name'],
                'phone' => $attributes['phone'] ?? null,
                'staff_code' => $attributes['staff_code'] ?? null,
                'pin_hash' => $minted['hash'],
                'position' => $attributes['position'],
                'status' => StaffStatus::Active->value,
                'hired_at' => $attributes['hired_at'] ?? null,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $staff->forceFill($minted['verifier']);
            $staff->save();

            $branches = $this->syncBranches->handle($staff, $attributes['branch_ids'] ?? null, $actor);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'pos_staff.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: PosStaff::class,
                auditableId: $staff->id,
                newValues: [
                    'name' => $staff->name,
                    'position' => $staff->position?->value,
                    'branch_id' => $staff->branch_id,
                    'branch_ids' => $branches['new'],
                    'staff_code' => $staff->staff_code,
                    'hired_at' => $staff->hired_at?->toDateString(),
                ],
            ));

            return [
                'staff' => $staff,
                'plaintext_pin' => $minted['pin'],
            ];
        });
    }
}

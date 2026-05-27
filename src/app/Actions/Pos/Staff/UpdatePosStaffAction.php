<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Partial-update a staff row: name / phone / staff_code /
 * position / branch_id / hired_at.
 *
 * PIN is intentionally NOT updatable here — it has its own
 * dedicated reset action so the one-shot plaintext envelope
 * doesn't have to thread through every update payload.
 *
 * Refuses cross-tenant writes (defence in depth — controller
 * already guards) and cross-tenant branch moves (the new
 * branch must belong to the actor's company).
 *
 * Audit event: `pos_staff.updated` with old + new diffs for
 * every field that actually changed.
 */
final readonly class UpdatePosStaffAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, phone?: string|null, staff_code?: string|null, position?: string, branch_id?: int, hired_at?: string|null}  $attributes
     */
    public function handle(PosStaff $staff, array $attributes, User $actor): PosStaff
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $staff->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($staff, $attributes, $actor, $companyId): PosStaff {
            $changes = [];

            // Simple fields — only mark a change when the new
            // value actually differs (otherwise we'd pollute the
            // audit log with no-op rows on every save).
            foreach (['name', 'phone', 'staff_code', 'position', 'hired_at'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $staff->{$field};

                // Enum comparison needs the value; date comparison
                // needs the formatted string.
                $oldComparable = match (true) {
                    $oldValue instanceof \BackedEnum => $oldValue->value,
                    $oldValue instanceof \DateTimeInterface => $oldValue->format('Y-m-d'),
                    default => $oldValue,
                };

                if ($oldComparable !== $newValue) {
                    $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                    $staff->{$field} = $newValue;
                }
            }

            // Branch move — re-check tenancy of the target.
            if (array_key_exists('branch_id', $attributes)
                && (int) $attributes['branch_id'] !== (int) $staff->branch_id
            ) {
                $branch = Branch::query()
                    ->where('id', $attributes['branch_id'])
                    ->where('company_id', $companyId)
                    ->first();
                if ($branch === null) {
                    throw new RuntimeException(
                        'The selected branch does not belong to your company.',
                    );
                }

                $changes['branch_id'] = [
                    'old' => $staff->branch_id,
                    'new' => $branch->id,
                ];
                $staff->branch_id = $branch->id;
            }

            $staff->save();

            if ($changes !== []) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'pos_staff.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: PosStaff::class,
                    auditableId: $staff->id,
                    oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                    newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
                ));
            }

            return $staff->fresh();
        });
    }
}

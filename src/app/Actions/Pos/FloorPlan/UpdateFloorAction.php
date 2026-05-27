<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Floor;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Partial-update a floor: name / name_ar / display_order /
 * status. Refuses cross-tenant; writes floor.updated audit
 * with old/new diffs of only the fields that changed.
 */
final readonly class UpdateFloorAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, name_ar?: string|null, display_order?: int, status?: string}  $attributes
     */
    public function handle(Floor $floor, array $attributes, User $actor): Floor
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $floor->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($floor, $attributes, $actor, $companyId): Floor {
            $changes = [];

            foreach (['name', 'name_ar', 'display_order', 'status'] as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $floor->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;
                if ($oldComparable == $newValue) { // loose: "1" vs 1
                    continue;
                }
                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $floor->{$field} = $newValue;
            }

            if ($changes === []) {
                return $floor->fresh();
            }

            $floor->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'floor.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $floor->branch_id,
                auditableType: Floor::class,
                auditableId: $floor->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $floor->fresh();
        });
    }
}

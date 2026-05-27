<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOnGroup;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — partial-update an add-on group.
 *
 * Diff-aware audit (catalogue.addon_group.updated) so the log
 * shows what specifically changed (e.g. flipping is_global from
 * false to true is significant — every product now sees this
 * group).
 */
final readonly class UpdateAddOnGroupAction
{
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'selection_mode',
        'is_global',
        'display_order',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(AddOnGroup $group, array $attributes, User $actor): AddOnGroup
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $group->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($group, $attributes, $actor, $companyId): AddOnGroup {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $group->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;
                if ($oldComparable == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $group->{$field} = $newValue;
            }

            if ($changes === []) {
                return $group->fresh();
            }

            $group->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon_group.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOnGroup::class,
                auditableId: $group->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $group->fresh();
        });
    }
}

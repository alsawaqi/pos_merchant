<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v2 #13 — update an alternate unit's factor / Arabic label / order. The NAME
 * is immutable (see UpdateIngredientUnitRequest). Diff-aware audit
 * (inventory.ingredient_unit.updated) so a factor change — which re-scales how
 * future entries in this unit land in base stock — is traceable.
 */
final readonly class UpdateIngredientUnitAction
{
    private const MUTABLE_FIELDS = ['name_ar', 'factor', 'sort_order'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(IngredientAltUnit $unit, array $attributes, User $actor): IngredientAltUnit
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($unit, $attributes, $actor, $companyId): IngredientAltUnit {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $new = $attributes[$field];
                $old = $unit->{$field};
                if ((string) $old === (string) $new) {
                    continue;
                }
                $changes[$field] = ['old' => $old, 'new' => $new];
                $unit->{$field} = $new;
            }

            if ($changes === []) {
                return $unit->fresh();
            }

            $unit->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient_unit.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: IngredientAltUnit::class,
                auditableId: $unit->id,
                oldValues: array_map(static fn (array $v): mixed => (string) $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => (string) $v['new'], $changes),
            ));

            return $unit->fresh();
        });
    }
}

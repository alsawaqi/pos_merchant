<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\Inventory\Containers;
use App\Support\Inventory\ContainerUsage;
use App\Support\Inventory\CountContainerMirror;
use App\Support\Inventory\PackSize;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #13 — update an alternate unit. Diff-aware audit
 * (inventory.ingredient_unit.updated).
 *
 * LAUNCH review add-on (A2, tester call 5) — a container's NAME and Arabic
 * name stay editable; its SIZE (factor, or what it holds) is LOCKED once the
 * container is used ({@see ContainerUsage}) — the merchant adds a new
 * container instead. An unused container can still be re-sized (or nested).
 * A3 — when it is the count container, the mirror columns devices read
 * follow the change.
 */
final readonly class UpdateIngredientUnitAction
{
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
        /** @var Ingredient $ingredient */
        $ingredient = Ingredient::query()->findOrFail($unit->ingredient_id);

        $target = [];
        if (array_key_exists('name', $attributes)) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw new RuntimeException('A container name is required.');
            }
            $problem = $ingredient->unit !== null ? PackSize::nameProblem($ingredient->unit, $name) : null;
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            $target['name'] = $name;
        }
        if (array_key_exists('name_ar', $attributes)) {
            $target['name_ar'] = $attributes['name_ar'] !== null && trim((string) $attributes['name_ar']) !== '' ? trim((string) $attributes['name_ar']) : null;
        }
        if (array_key_exists('sort_order', $attributes)) {
            $target['sort_order'] = (int) $attributes['sort_order'];
        }

        $sizeSent = array_key_exists('factor', $attributes) || array_key_exists('contains_unit_id', $attributes);
        if ($sizeSent) {
            [$factor, $containsId, $containsQuantity] = CreateIngredientUnitAction::size($ingredient, $attributes, $unit);
            $sizeChanged = abs((float) $factor - (float) $unit->factor) > 1e-9
                || (int) ($containsId ?? 0) !== (int) ($unit->contains_unit_id ?? 0)
                || abs((float) ($containsQuantity ?? 0) - (float) ($unit->contains_quantity ?? 0)) > 1e-9;
            if ($sizeChanged) {
                $target['factor'] = $factor;
                $target['contains_unit_id'] = $containsId;
                $target['contains_quantity'] = $containsQuantity;
            }
        }

        return DB::transaction(function () use ($unit, $target, $actor, $companyId, $ingredient): IngredientAltUnit {
            // Fix order B-1 (L6) — the size lock is checked INSIDE the
            // transaction with the container row locked FOR UPDATE: a purchase,
            // transfer, count or waste writing its breakdown holds a share lock
            // on the item's containers, so it either finishes first (and the
            // container is then "used") or waits for the resize.
            if (array_key_exists('factor', $target)) {
                IngredientAltUnit::query()->whereKey($unit->id)->lockForUpdate()->first();
                if (ContainerUsage::isUsed($unit)) {
                    throw new RuntimeException(ContainerUsage::MESSAGE);
                }
            }
            $changes = [];
            foreach ($target as $field => $new) {
                $old = $unit->{$field};
                if ((string) ($old ?? '') === (string) ($new ?? '') && ($old === null) === ($new === null)) {
                    continue;
                }
                $changes[$field] = ['old' => $old, 'new' => $new];
                $unit->{$field} = $new;
            }

            if ($changes === []) {
                return $unit->fresh();
            }

            // Same name + size + content twice among live rows is refused.
            $name = (string) $unit->name;
            $clash = IngredientAltUnit::query()
                ->where('ingredient_id', $unit->ingredient_id)
                ->where('id', '!=', $unit->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->get()
                ->first(static fn (IngredientAltUnit $c): bool => abs((float) $c->factor - (float) $unit->factor) < 1e-9
                    && (int) ($c->contains_unit_id ?? 0) === (int) ($unit->contains_unit_id ?? 0));
            if ($clash !== null) {
                throw new RuntimeException(sprintf("This item already has a '%s' of that size (%s).", $name, Containers::displayName($clash, $ingredient)));
            }

            $unit->save();

            // A3 — the count container's mirror follows a rename / resize.
            if ((int) ($ingredient->count_container_id ?? 0) === (int) $unit->id) {
                CountContainerMirror::apply($ingredient, $unit->fresh());
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient_unit.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: IngredientAltUnit::class,
                auditableId: $unit->id,
                oldValues: array_map(static fn (array $v): ?string => $v['old'] === null ? null : (string) $v['old'], $changes),
                newValues: array_map(static fn (array $v): ?string => $v['new'] === null ? null : (string) $v['new'], $changes),
            ));

            return $unit->fresh();
        });
    }
}

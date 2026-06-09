<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #13 — add an alternate unit to an ingredient.
 *
 * Contextual guards (clean 422s): the name can't equal the ingredient's BASE
 * unit, and an ingredient can't have two units with the same name. Because the
 * (ingredient_id, name) unique index spans soft-deleted rows, a previously
 * deleted unit of the same name is RESTORED-and-updated rather than re-inserted
 * (which would hit the constraint).
 *
 * Audit event: inventory.ingredient_unit.created.
 */
final readonly class CreateIngredientUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, factor: numeric-string|float|int, sort_order?: int}  $attributes
     */
    public function handle(Ingredient $ingredient, array $attributes, User $actor): IngredientAltUnit
    {
        $companyId = $this->tenant->requiredId();
        $name = trim((string) $attributes['name']);

        if ($name === '') {
            throw new RuntimeException('A unit name is required.');
        }
        if ($name === $ingredient->unit?->value) {
            throw new RuntimeException("An alternate unit can't be named the same as the base unit.");
        }

        return DB::transaction(function () use ($ingredient, $attributes, $actor, $companyId, $name): IngredientAltUnit {
            $existing = IngredientAltUnit::withTrashed()
                ->where('ingredient_id', $ingredient->id)
                ->where('name', $name)
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw new RuntimeException("This ingredient already has a '{$name}' unit.");
            }

            $payload = [
                'name_ar' => $attributes['name_ar'] ?? null,
                'factor' => $attributes['factor'],
                'sort_order' => $attributes['sort_order'] ?? 0,
            ];

            if ($existing !== null) {
                $existing->restore();
                $existing->fill($payload)->save();
                $unit = $existing;
            } else {
                /** @var IngredientAltUnit $unit */
                $unit = IngredientAltUnit::query()->create($payload + [
                    'company_id' => $companyId,
                    'ingredient_id' => $ingredient->id,
                    'name' => $name,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient_unit.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: IngredientAltUnit::class,
                auditableId: $unit->id,
                newValues: [
                    'ingredient_id' => $ingredient->id,
                    'name' => $unit->name,
                    'factor' => (string) $unit->factor,
                ],
            ));

            return $unit;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\Inventory\PackSize;
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
        // Not the stored unit itself, nor its PD4 metric pair (g when the base
        // is kg, ml when the base is l...), which the system already provides:
        // the dropdown never shows the same name twice. LAUNCH item kind — one
        // rule (and wording) with the pack sizes of the create form.
        $nameProblem = $ingredient->unit !== null ? PackSize::nameProblem($ingredient->unit, $name) : null;
        if ($nameProblem !== null) {
            throw new RuntimeException($nameProblem);
        }

        return DB::transaction(function () use ($ingredient, $attributes, $actor, $companyId, $name): IngredientAltUnit {
            $existing = IngredientAltUnit::withTrashed()
                ->where('ingredient_id', $ingredient->id)
                ->where('name', $name)
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw new RuntimeException("This item already has a '{$name}' pack size.");
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

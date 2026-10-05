<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\Inventory\Containers;
use App\Support\Inventory\PackSize;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #13 — add an alternate unit to an ingredient.
 *
 * LAUNCH review add-on (A2) — these rows are the item's CONTAINERS. The same
 * word may be used with different sizes ("bottle 1.5 l" and "bottle 500 ml");
 * only the same name + size + content twice among LIVE rows is refused. A
 * container may hold N of another container of the SAME item ("crate holds
 * 12 × bottle 1 l"): N is a whole number ≥ 2, the nesting is at most
 * {@see Containers::MAX_DEPTH} deep, and the factor is N × the content's
 * factor (base units in ONE). A soft-deleted container is never restored by
 * name any more — names are not unique, so a new row is created.
 *
 * Contextual guards (clean 422s): the name can't be a unit of the item's kind
 * or start with '#' / '@' (tokens).
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
     * @param  array{name: string, name_ar?: string|null, factor?: numeric-string|float|int, sort_order?: int, contains_unit_id?: int|null, contains_quantity?: numeric-string|int|null}  $attributes
     */
    public function handle(Ingredient $ingredient, array $attributes, User $actor): IngredientAltUnit
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }
        $name = trim((string) $attributes['name']);

        if ($name === '') {
            throw new RuntimeException('A container name is required.');
        }
        // Not the stored unit itself, nor its PD4 metric pair (g when the base
        // is kg, ml when the base is l...), which the system already provides:
        // the dropdown never shows the same name twice. LAUNCH item kind — one
        // rule (and wording) with the pack sizes of the create form.
        $nameProblem = $ingredient->unit !== null ? PackSize::nameProblem($ingredient->unit, $name) : null;
        if ($nameProblem !== null) {
            throw new RuntimeException($nameProblem);
        }

        [$factor, $containsId, $containsQuantity] = self::size($ingredient, $attributes);

        return DB::transaction(function () use ($ingredient, $attributes, $actor, $companyId, $name, $factor, $containsId, $containsQuantity): IngredientAltUnit {
            $duplicate = IngredientAltUnit::query()
                ->where('ingredient_id', $ingredient->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->get()
                ->first(static fn (IngredientAltUnit $c): bool => abs((float) $c->factor - (float) $factor) < 1e-9
                    && (int) ($c->contains_unit_id ?? 0) === (int) ($containsId ?? 0));
            if ($duplicate !== null) {
                throw new RuntimeException(sprintf(
                    "This item already has a '%s' of that size (%s). Use a different name or size.",
                    $name,
                    Containers::displayName($duplicate, $ingredient),
                ));
            }

            /** @var IngredientAltUnit $unit */
            $unit = IngredientAltUnit::query()->create([
                'company_id' => $companyId,
                'ingredient_id' => $ingredient->id,
                'name' => $name,
                'name_ar' => isset($attributes['name_ar']) && trim((string) $attributes['name_ar']) !== '' ? trim((string) $attributes['name_ar']) : null,
                'factor' => $factor,
                'sort_order' => $attributes['sort_order'] ?? 0,
                'contains_unit_id' => $containsId,
                'contains_quantity' => $containsQuantity,
            ]);

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
                    'contains_unit_id' => $containsId,
                    'contains_quantity' => $containsQuantity,
                ],
            ));

            return $unit;
        });
    }

    /**
     * The container's size: a factor as given, or N × another container of the
     * same item. Returns [factor (4dp string), contains_unit_id, contains_quantity].
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: int|null, 2: string|null}
     *
     * @throws RuntimeException
     */
    public static function size(Ingredient $ingredient, array $attributes, ?IngredientAltUnit $self = null): array
    {
        $containsId = $attributes['contains_unit_id'] ?? null;
        if ($containsId === null || $containsId === '') {
            $factor = $attributes['factor'] ?? null;
            if (! is_numeric($factor) || (float) $factor <= 0) {
                throw new RuntimeException('Enter how much the container holds.');
            }
            if ((float) $factor > PackSize::MAX_FACTOR) {
                throw new RuntimeException(sprintf('A container can hold at most %s %s.', number_format(PackSize::MAX_FACTOR), $ingredient->unit?->value ?? ''));
            }

            return [number_format((float) $factor, 4, '.', ''), null, null];
        }

        $child = IngredientAltUnit::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereKey((int) $containsId)
            ->first();
        if ($child === null) {
            throw new RuntimeException('A container can only hold another container of the same item.');
        }
        if ($self !== null && self::reaches($child, (int) $self->id)) {
            throw new RuntimeException('A container cannot hold itself (directly or through another container).');
        }
        $quantity = $attributes['contains_quantity'] ?? null;
        if (! is_numeric($quantity) || (float) $quantity < 2 || floor((float) $quantity) != (float) $quantity) {
            throw new RuntimeException('A container holds a whole number (2 or more) of another container.');
        }
        if (Containers::depth($child) >= Containers::MAX_DEPTH) {
            throw new RuntimeException(sprintf('Containers nest at most %d deep (e.g. a carton of crates of bottles).', Containers::MAX_DEPTH));
        }
        $factor = Containers::decimal((string) $child->factor)->multipliedBy((int) $quantity);
        if ($factor->isGreaterThan(PackSize::MAX_FACTOR)) {
            throw new RuntimeException(sprintf('A container can hold at most %s %s.', number_format(PackSize::MAX_FACTOR), $ingredient->unit?->value ?? ''));
        }

        return [(string) $factor->toScale(4), (int) $child->id, number_format((int) $quantity, 4, '.', '')];
    }

    /** Whether $from (or anything it holds) is the container with id $target. */
    private static function reaches(IngredientAltUnit $from, int $target): bool
    {
        $current = $from;
        for ($i = 0; $i <= Containers::MAX_DEPTH + 1; $i++) {
            if ((int) $current->id === $target) {
                return true;
            }
            if ($current->contains_unit_id === null) {
                return false;
            }
            $next = IngredientAltUnit::withTrashed()->find($current->contains_unit_id);
            if ($next === null) {
                return false;
            }
            $current = $next;
        }

        return true;
    }
}

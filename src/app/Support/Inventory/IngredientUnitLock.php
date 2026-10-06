<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\ProductRecipe;
use App\Models\StockMovement;

/**
 * The unit-change rule (Phase 5a), shared by the update guard and the
 * ingredient list (LAUNCH item kind, A2: the edit form locks the kind up
 * front instead of failing on save).
 *
 * An ingredient's unit — and so its kind — CANNOT change once any movement,
 * non-zero stock, or recipe / add-on / prep line exists for it: every one of
 * them is denominated in the stored unit ("1.000" of kg is not "1.000" of g).
 */
final class IngredientUnitLock
{
    public const MESSAGE = 'Cannot change the unit of an ingredient that already has stock, movements, or recipe/add-on usage. Remove those references first, then create a new ingredient with the new unit.';

    public static function isLocked(Ingredient $ingredient): bool
    {
        return self::lockedIds([(int) $ingredient->id]) !== [];
    }

    /**
     * The ids (of $ids) whose unit can no longer change — one query per kind
     * of reference, whatever the number of ingredients.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public static function lockedIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $locked = [];
        $sources = [
            StockMovement::query()->whereIn('ingredient_id', $ids),
            BranchStock::query()->whereIn('ingredient_id', $ids)->where('quantity', '!=', '0.000'),
            // A recipe / add-on consumption line stores its quantity in the
            // ingredient's CURRENT stored unit (the portal converts at entry).
            ProductRecipe::query()->whereIn('ingredient_id', $ids),
            AddOnConsumption::query()->whereIn('ingredient_id', $ids),
            // Legacy single-ingredient add-on (pos_addons.ingredient_id /
            // ingredient_qty) — still read by the sale-time deduction.
            AddOn::query()->whereIn('ingredient_id', $ids),
            // LAUNCH-P3 P3-4 — a prep recipe line is per batch in this unit too.
            IngredientRecipe::query()->whereIn('ingredient_id', $ids),
            // LAUNCH packaging add-on (fix order PK-B1, H1) — a live per-order
            // packaging line stores its quantity in this unit too (soft-
            // deleted lines are out through the model's scope).
            \App\Models\OrderPackagingLine::query()->whereIn('ingredient_id', $ids),
        ];
        foreach ($sources as $query) {
            foreach ($query->distinct()->pluck('ingredient_id') as $id) {
                $locked[(int) $id] = true;
            }
        }

        return $locked;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;

/**
 * LAUNCH review add-on (A3, tester call 6) — the "Count container" form field
 * is gone; a container row marked "Tills count in this" replaces it. The four
 * pos_ingredients columns tills, handhelds and pos_api read —
 * piece_unit_label, piece_unit_label_ar, units_per_piece (a JSON number in the
 * device config) and allow_fractional_pieces — stay, as a MIRROR of that row:
 * set, changed or removed together with count_container_id, never on their
 * own. Removing it makes tills count the item in l / kg after their next
 * settings refresh (the portal warns first).
 */
final class CountContainerMirror
{
    /**
     * Point the item's count container at $container (null = none) and copy
     * its name, Arabic name and size into the mirror columns. Returns the
     * changed attributes (old → new) for the audit row; saves the ingredient
     * only when something changed (a real change must reach devices, so
     * updated_at moves with it).
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function apply(Ingredient $ingredient, ?IngredientAltUnit $container): array
    {
        $target = $container === null
            ? ['count_container_id' => null, 'piece_unit_label' => null, 'piece_unit_label_ar' => null, 'units_per_piece' => null]
            : [
                'count_container_id' => (int) $container->id,
                'piece_unit_label' => mb_substr((string) $container->name, 0, 32),
                'piece_unit_label_ar' => $container->name_ar !== null && trim((string) $container->name_ar) !== '' ? mb_substr((string) $container->name_ar, 0, 32) : null,
                'units_per_piece' => (string) $container->factor,
            ];

        $changes = [];
        foreach ($target as $field => $new) {
            $old = $ingredient->{$field};
            $same = $field === 'units_per_piece'
                ? (($old === null) === ($new === null)) && ($old === null || abs((float) $old - (float) $new) < 1e-9)
                : (string) ($old ?? '') === (string) ($new ?? '') && ($old === null) === ($new === null);
            if ($same) {
                continue;
            }
            $changes[$field] = ['old' => $old !== null ? (string) $old : null, 'new' => $new];
            $ingredient->{$field} = $new;
        }

        if ($changes !== []) {
            $ingredient->save();
        }

        return $changes;
    }

    /** Whether the item's mirror still matches a count container row (or there is none). */
    public static function isConsistent(Ingredient $ingredient): bool
    {
        if ($ingredient->count_container_id === null) {
            return true;
        }
        $container = IngredientAltUnit::query()->find($ingredient->count_container_id);

        return $container !== null
            && $ingredient->piece_unit_label === mb_substr((string) $container->name, 0, 32)
            && abs((float) $ingredient->units_per_piece - (float) $container->factor) < 1e-9;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use RuntimeException;

/**
 * v2 #13 — convert a quantity ENTERED in some unit to the ingredient's BASE
 * unit, in which all stock is stored.
 *
 * The whole unit-conversion design is "convert-at-entry, store-in-base": every
 * place a human types a quantity (restock / recipe line / adjust / transfer /
 * waste / restock request) may name a unit; this turns it into base units so the
 * rest of the system (device availability, pos_api consumption, reports) keeps
 * working unchanged in base units.
 *
 *   $unit === null OR the ingredient's base unit  → factor 1 (already base)
 *   an alt unit's name                            → × that unit's factor
 *   anything else                                 → RuntimeException (422)
 */
final readonly class IngredientUnitConverter
{
    /**
     * @param  float|int|string  $quantity  the quantity as entered
     * @param  string|null  $unit  the unit it was entered in (null = base unit)
     * @return float  the equivalent quantity in the ingredient's base unit
     */
    public function toBase(Ingredient $ingredient, float|int|string $quantity, ?string $unit = null): float
    {
        return (float) $quantity * $this->factorFor($ingredient, $unit);
    }

    /**
     * Base units per ONE of [$unit]. 1.0 for the base unit (or null).
     */
    public function factorFor(Ingredient $ingredient, ?string $unit): float
    {
        $base = $ingredient->unit?->value;
        if ($unit === null || $unit === '' || $unit === $base) {
            return 1.0;
        }

        $alt = IngredientAltUnit::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('name', $unit)
            ->first();

        if ($alt === null) {
            throw new RuntimeException("Unit '{$unit}' is not defined for this ingredient.");
        }

        return (float) $alt->factor;
    }
}

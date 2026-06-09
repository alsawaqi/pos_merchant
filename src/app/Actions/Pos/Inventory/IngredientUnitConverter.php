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
     * Every base-unit quantity column (pos_branch_stock, pos_stock_movements,
     * pos_product_recipes, restock-request lines) is decimal(12,3) → it can hold
     * at most ±999,999,999.999. A large factor × a large entered quantity can
     * exceed that, so we reject the conversion rather than let the DB overflow /
     * silently truncate the value.
     */
    private const MAX_BASE_QUANTITY = 999999999.999;

    /**
     * @param  float|int|string  $quantity  the quantity as entered
     * @param  string|null  $unit  the unit it was entered in (null = base unit)
     * @return float  the equivalent quantity in the ingredient's base unit
     */
    public function toBase(Ingredient $ingredient, float|int|string $quantity, ?string $unit = null): float
    {
        $result = (float) $quantity * $this->factorFor($ingredient, $unit);

        if (abs($result) > self::MAX_BASE_QUANTITY) {
            throw new RuntimeException(sprintf(
                'The converted quantity (%s) exceeds the maximum storable amount of 999,999,999.999 in the base unit — use a smaller quantity or unit.',
                rtrim(rtrim(number_format($result, 3, '.', ''), '0'), '.'),
            ));
        }

        return $result;
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

        // Defence-in-depth: the request layer enforces factor > 0, but if a
        // corrupt / non-positive factor ever reached the DB it would silently
        // flip a signed adjustment's direction. Refuse it here too.
        $factor = (float) $alt->factor;
        if ($factor <= 0) {
            throw new RuntimeException("Unit '{$unit}' has an invalid (non-positive) conversion factor.");
        }

        return $factor;
    }
}

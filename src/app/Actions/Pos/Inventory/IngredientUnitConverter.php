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
 * waste / restock request / goods received) may name a unit; this turns it
 * into base units so the rest of the system (device availability, pos_api
 * consumption, reports) keeps working unchanged in base units.
 *
 *   $unit === null OR the ingredient's base unit  → factor 1 (already base)
 *   '@piece' (LAUNCH-P2)                          → × the ingredient's piece ratio
 *   an alt unit's name                            → × that unit's factor
 *   kg↔g / l↔ml                                   → × the metric factor
 *   lb / oz, gal / fl oz (item kind G1)           → × the exact US size
 *   anything else                                 → RuntimeException (422)
 */
final readonly class IngredientUnitConverter
{
    /**
     * LAUNCH-P2 P2-3 — the token that names an ingredient's PIECE unit (its
     * piece_unit_label × units_per_piece, or the base unit 'piece' itself).
     * A token rather than the free-text label, so it can never collide with
     * an alternate unit's name.
     */
    public const PIECE_UNIT = '@piece';

    /**
     * Every base-unit quantity column (pos_branch_stock, pos_stock_movements,
     * pos_product_recipes, restock-request lines, …) is numeric(14,4) since
     * LAUNCH-P2. A large factor × a large entered quantity can still exceed
     * what the business should ever hold, so the conversion keeps the
     * historical 9-integer-digit bound rather than let the DB overflow.
     */
    private const MAX_BASE_QUANTITY = 999999999.9999;

    /**
     * @param  float|int|string  $quantity  the quantity as entered
     * @param  string|null  $unit  the unit it was entered in (null = base unit)
     * @return float the equivalent quantity in the ingredient's base unit
     */
    public function toBase(Ingredient $ingredient, float|int|string $quantity, ?string $unit = null): float
    {
        $result = (float) $quantity * $this->factorFor($ingredient, $unit);

        if (abs($result) > self::MAX_BASE_QUANTITY) {
            throw new RuntimeException(sprintf(
                'The converted quantity (%s) exceeds the maximum storable amount of 999,999,999.9999 in the base unit — use a smaller quantity or unit.',
                rtrim(rtrim(number_format($result, 4, '.', ''), '0'), '.'),
            ));
        }

        return $result;
    }

    /**
     * Base units per ONE of [$unit]. 1.0 for the base unit (or null).
     *
     * Resolution order: base passthrough → the piece token → custom alternate
     * unit (explicit merchant data wins) → PD4 system-provided metric sibling
     * (kg<->g, l<->ml) → unknown = 422.
     */
    public function factorFor(Ingredient $ingredient, ?string $unit): float
    {
        $base = $ingredient->unit?->value;
        if ($unit === null || $unit === '' || $unit === $base) {
            return 1.0;
        }

        if ($unit === self::PIECE_UNIT) {
            $ratio = $ingredient->unitsPerPiece();
            if ($ratio === null || $ratio <= 0) {
                throw new RuntimeException('This ingredient has no piece unit — set its piece unit first, or use another unit.');
            }

            return $ratio;
        }

        $alt = IngredientAltUnit::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('name', $unit)
            ->first();

        if ($alt !== null) {
            // Defence-in-depth: the request layer enforces factor > 0, but if a
            // corrupt / non-positive factor ever reached the DB it would
            // silently flip a signed adjustment's direction. Refuse it here too.
            $factor = (float) $alt->factor;
            if ($factor <= 0) {
                throw new RuntimeException("Unit '{$unit}' has an invalid (non-positive) conversion factor.");
            }

            return $factor;
        }

        // PD4 — same-family metric units the system provides automatically
        // (no IngredientAltUnit row needed): base kg accepts 'g', base l
        // accepts 'ml', and so on; LAUNCH item kind G1 — and the US units
        // (gal / fl oz, lb / oz) at their exact sizes. Count units
        // (piece/pack/box) have none.
        $siblings = $ingredient->unit?->convertibleUnits() ?? [];
        if (isset($siblings[$unit])) {
            return $siblings[$unit];
        }

        throw new RuntimeException("Unit '{$unit}' is not defined for this ingredient.");
    }
}

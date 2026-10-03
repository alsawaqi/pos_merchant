<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Enums\IngredientUnit;
use RuntimeException;

/**
 * LAUNCH item kind — a PACK SIZE (crate, sack, box) is how an item is
 * bought: "crate holds 12 l", "sack holds 25 kg", "box holds 24 pieces".
 * It is stored as an alternate unit whose factor is the amount converted to
 * the item's stored unit (crate of a ml item: 12 l → 12000), so receiving 3
 * crates adds 36 l by itself. Nobody types a factor any more.
 *
 * The amount may be typed in any unit of the item's kind (kg/g; l/ml; the
 * count unit). The factor keeps 4 decimals and the alternate-unit cap.
 */
final class PackSize
{
    /** Same cap as an alternate unit's factor (CreateIngredientUnitRequest). */
    public const MAX_FACTOR = 1000000;

    /** Why $unit cannot measure a pack of an item stored in $stored, or null. */
    public static function unitProblem(IngredientUnit $stored, string $unit): ?string
    {
        if ($stored->factorOf($unit) !== null) {
            return null;
        }

        $units = $stored->kindUnits();
        $last = array_pop($units);

        return sprintf('A pack size of this item holds %s.', $units === [] ? $last : implode(', ', $units).' or '.$last);
    }

    /**
     * Why $name cannot be a pack size's name, or null: a unit of the item's
     * kind already exists under that name (stored g: "g" and "kg").
     */
    public static function nameProblem(IngredientUnit $stored, string $name): ?string
    {
        if ($stored->factorOf(trim($name)) === null) {
            return null;
        }

        return sprintf("'%s' is already a unit of this item — give the pack its own name (crate, sack, box).", trim($name));
    }

    /**
     * The factor (a 4-decimal string) of a pack holding $amount $unit, in
     * $stored units. Refuses a unit outside the kind, an amount that rounds
     * away or moves by more than 1% at 4 decimals, and the cap.
     */
    public static function factor(IngredientUnit $stored, float|int|string $amount, string $unit): string
    {
        $problem = self::unitProblem($stored, $unit);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $exact = (float) $amount * (float) $stored->factorOf($unit);
        $factor = round($exact, 4);
        $typed = self::trim((float) $amount).' '.$unit;
        if ($factor <= 0) {
            throw new RuntimeException("{$typed} is too small for a pack size.");
        }
        if (abs($factor - $exact) > $exact * 0.01) {
            throw new RuntimeException("{$typed} cannot be kept accurately (4 decimals of {$stored->value}) — use a smaller unit.");
        }
        if ($factor > self::MAX_FACTOR) {
            throw new RuntimeException(sprintf('A pack size can hold at most %s %s.', number_format(self::MAX_FACTOR), $stored->value));
        }

        return number_format($factor, 4, '.', '');
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

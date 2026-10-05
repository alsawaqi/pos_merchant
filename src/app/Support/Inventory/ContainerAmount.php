<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * LAUNCH review add-on (C1, D1–D4; tester call 4) — an amount entered BY
 * CONTAINER: pieces of one or more containers, and an amount that fills in as
 * pieces × size. The amount may be LOWERED (a broken bottle, a half-used one)
 * but never RAISED above what the pieces hold:
 *
 *   cap    = Σ pieces × factor, in the stored unit at 4 decimals, HALF_UP
 *   amount = the typed amount converted to the stored unit (4 decimals,
 *            HALF_UP), or the cap when none was typed; 0 < amount ≤ cap
 *
 * Every container must be a live container of THIS ingredient (looked up by
 * uuid or token within the item, so another item's — or another company's —
 * container never applies its size). A line with no container keeps the free
 * amount (loose weight), handled by the callers as before.
 */
final class ContainerAmount
{
    /** The largest amount the stock columns hold (numeric(14,4)), as IngredientUnitConverter. */
    public const MAX_BASE = '999999999.9999';

    /**
     * Resolve [{container_uuid, pieces}] rows of one ingredient.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{container: IngredientAltUnit, pieces: BigDecimal}>
     *
     * @throws RuntimeException a container not of this item, or pieces that are not allowed
     */
    public static function rows(Ingredient $ingredient, array $rows, bool $allowZero = false): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = (string) ($row['container_uuid'] ?? $row['container'] ?? '');
            $container = Containers::findByUuid($ingredient, $ref);
            if ($container === null) {
                throw new RuntimeException(sprintf('A container on the "%s" line is not one of its containers.', $ingredient->name));
            }
            $out[] = self::row($ingredient, $container, Containers::decimal($row['pieces'] ?? '0'), $row['leaf_pieces'] ?? null, $allowZero);
        }

        return $out;
    }

    /**
     * One resolved row. Fix order B-2 (the owner's broken bottle) — a NESTED
     * container (2 × crate of 12 × bottle 1 l) may say how many of its inner
     * containers really came: leaf_pieces ("23 bottles", one broken). It
     * defaults to the full count, may be lowered, never raised; the amount and
     * the breakdown then follow it (23 bottles = 23 l, still lowerable).
     *
     * @return array{container: IngredientAltUnit, pieces: BigDecimal, leaf: ?IngredientAltUnit, leaf_pieces: ?BigDecimal}
     *
     * @throws RuntimeException
     */
    public static function row(Ingredient $ingredient, IngredientAltUnit $container, BigDecimal $pieces, mixed $leafPieces, bool $allowZero = false): array
    {
        self::checkPieces($ingredient, $container, $pieces, $allowZero);
        $row = ['container' => $container, 'pieces' => $pieces, 'leaf' => null, 'leaf_pieces' => null];
        if ($leafPieces === null || $leafPieces === '' || $container->contains_unit_id === null) {
            return $row;
        }

        [$leaf, $perLeaf] = Containers::leaf($container, Containers::of($ingredient));
        $full = $pieces->multipliedBy($perLeaf);
        $count = Containers::decimal($leafPieces);
        self::checkPieces($ingredient, $leaf, $count, $allowZero);
        if ($count->isGreaterThan($full)) {
            throw new RuntimeException(sprintf(
                '%s: %s × %s is more than %s %s hold (%s). The count may be lowered (a broken one), never raised.',
                $ingredient->name,
                Containers::trim((string) $count),
                $leaf->name,
                Containers::trim((string) $pieces),
                $container->name,
                Containers::trim((string) $full),
            ));
        }
        if ($count->isEqualTo($full)) {
            return $row;
        }

        return ['container' => $container, 'pieces' => $pieces, 'leaf' => $leaf, 'leaf_pieces' => $count];
    }

    /**
     * @throws RuntimeException
     */
    public static function checkPieces(Ingredient $ingredient, IngredientAltUnit $container, BigDecimal $pieces, bool $allowZero = false): void
    {
        if ($pieces->isNegative() || (! $allowZero && $pieces->isZero())) {
            throw new RuntimeException(sprintf('Enter how many %s for "%s".', $container->name, $ingredient->name));
        }
        if ($pieces->getScale() > StockDecimal::QUANTITY_SCALE && ! $pieces->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::DOWN)->isEqualTo($pieces)) {
            throw new RuntimeException('Pieces keep at most 4 decimal places.');
        }
        if (! $ingredient->allow_fractional_pieces && ! $pieces->toScale(0, RoundingMode::DOWN)->isEqualTo($pieces)) {
            throw new RuntimeException(sprintf('"%s" is handled in whole containers — enter a whole number of %s.', $ingredient->name, $container->name));
        }
    }

    /**
     * What the pieces hold, in the stored unit (4 decimals, HALF_UP).
     *
     * @param  list<array{container: IngredientAltUnit, pieces: BigDecimal}>  $rows
     */
    public static function cap(array $rows): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($rows as $row) {
            // Fix order B-2 — a lowered inner count holds its leaves only.
            $sum = isset($row['leaf_pieces'], $row['leaf']) && $row['leaf_pieces'] instanceof BigDecimal
                ? $sum->plus($row['leaf_pieces']->multipliedBy(Containers::decimal((string) $row['leaf']->factor)))
                : $sum->plus($row['pieces']->multipliedBy(Containers::decimal((string) $row['container']->factor)));
        }

        return $sum->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    /**
     * The amount in the stored unit: the typed amount (in $unit, null = the
     * stored unit) or, when none was typed, the cap. Refuses an amount above
     * the cap or not above 0 (a count may be 0 when $allowZero).
     *
     * @param  list<array{container: IngredientAltUnit, pieces: BigDecimal}>  $rows
     *
     * @throws RuntimeException
     */
    public static function amount(Ingredient $ingredient, array $rows, mixed $amount, ?string $unit, IngredientUnitConverter $units, bool $allowZero = false): BigDecimal
    {
        $cap = self::cap($rows);
        // Fix order B-1 (L1) — the same maximum as a loose line: what the
        // pieces hold can never be more than the stock columns store.
        if ($cap->isGreaterThan(BigDecimal::of(self::MAX_BASE))) {
            throw new RuntimeException(sprintf(
                'The converted quantity (%s) exceeds the maximum storable amount of 999,999,999.9999 in the base unit — use a smaller quantity or unit.',
                (string) $cap->stripTrailingZeros(),
            ));
        }
        if ($amount === null || $amount === '') {
            return $cap;
        }

        $factor = Containers::decimal(rtrim(rtrim(number_format($units->factorFor($ingredient, $unit), 10, '.', ''), '0'), '.'));
        $exact = $ingredient->unit?->convertibleUnitFactors()[(string) $unit] ?? null;
        if ($exact !== null) {
            $factor = BigDecimal::of($exact);
        }
        $base = Containers::decimal($amount)->multipliedBy($factor)->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);

        if ($base->isNegative() || (! $allowZero && $base->isZero())) {
            throw new RuntimeException(sprintf('The amount of "%s" must be more than 0.', $ingredient->name));
        }
        if ($base->isGreaterThan($cap)) {
            $stored = (string) ($ingredient->unit?->value ?? '');
            throw new RuntimeException(sprintf(
                '%s: %s is more than the containers hold (%s). The amount may be lowered (a part-used container) but never raised — add pieces instead.',
                $ingredient->name,
                Containers::friendly((string) $base, $stored),
                Containers::friendly((string) $cap, $stored),
            ));
        }

        return $base;
    }

    /** A snapshot label for a document row ("crate (12 × bottle 1 l)"), at most 80 characters. */
    public static function label(Ingredient $ingredient, IngredientAltUnit $container): string
    {
        return mb_substr(Containers::displayName($container, $ingredient, Containers::of($ingredient)), 0, 80);
    }
}

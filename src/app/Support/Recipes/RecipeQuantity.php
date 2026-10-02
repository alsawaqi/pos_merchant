<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Models\Ingredient;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;
use Throwable;

/**
 * LAUNCH-P3 P3-1 — a recipe amount as TYPED and as STORED.
 *
 * Product recipe lines, add-on stock-usage lines and prep recipe lines store
 * the BASE quantity (what the device API deducts) plus how the line was
 * entered (entered_unit / entered_quantity), so the editor reopens "5 g" as
 * "5 g", never as "0.005 kg".
 *
 *   entered_unit  the unit token picked in the editor: the base unit's name,
 *                 its metric pair (g / kg, ml / l), an extra unit's name, or
 *                 '@piece' (the ingredient's piece unit). '' / NULL = base.
 *
 * An amount that would round to 0 in the base unit (4 decimals) is REFUSED
 * with a clear message — before P3 it was silently saved as 0.
 *
 * Reading back ({@see display()}): the entered form is shown only while it
 * still converts to the stored base quantity under the CURRENT unit factors.
 * If an extra unit was re-sized or deleted, or the piece ratio moved, the
 * line reopens in the base unit — never a silent re-conversion on save.
 */
final readonly class RecipeQuantity
{
    /** The historical 9-integer-digit bound ({@see IngredientUnitConverter}). */
    private const MAX_BASE = '999999999.9999';

    public function __construct(
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @return array{quantity: string, entered_unit: string, entered_quantity: string}
     *
     * @throws RuntimeException unknown unit, an amount that rounds to 0, or one too large to store
     */
    public function resolve(Ingredient $ingredient, string|int|float $quantity, ?string $unit): array
    {
        $token = $this->token($ingredient, $unit);
        $factor = $this->factor($ingredient, $token);
        $entered = BigDecimal::of(self::text($quantity));
        $exact = $entered->multipliedBy($factor);

        if ($exact->abs()->isGreaterThan(self::MAX_BASE)) {
            throw new RuntimeException(sprintf(
                '%s: %s %s is more than the largest amount that can be stored (999,999,999.9999 %s).',
                $ingredient->name,
                self::trim($entered),
                $this->label($ingredient, $token),
                $this->baseUnit($ingredient),
            ));
        }

        $base = $exact->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
        if ($base->isZero() && ! $entered->isZero()) {
            // The smallest amount that still records 0.0001 of the base unit.
            $minimum = BigDecimal::of('0.00005')->dividedBy($factor, StockDecimal::QUANTITY_SCALE, RoundingMode::UP);
            throw new RuntimeException(sprintf(
                '%s: %s %s is too small to record — it rounds to 0 %s (stock is kept in %s to 4 decimals). Enter at least %s %s, or use a smaller base unit for this ingredient.',
                $ingredient->name,
                self::trim($entered),
                $this->label($ingredient, $token),
                $this->baseUnit($ingredient),
                $this->baseUnit($ingredient),
                self::trim($minimum),
                $this->label($ingredient, $token),
            ));
        }

        return [
            'quantity' => (string) StockDecimal::quantity((string) $base),
            'entered_unit' => $token,
            'entered_quantity' => (string) StockDecimal::format((string) $entered->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP), 0, StockDecimal::QUANTITY_SCALE),
        ];
    }

    /**
     * How a stored line reads back in the editor: the entered unit + quantity
     * while they still equal the stored base quantity, else the base.
     *
     * @return array{unit: string, quantity: string, entered: bool}
     */
    public function display(Ingredient $ingredient, string|int|float $baseQuantity, ?string $enteredUnit, string|int|float|null $enteredQuantity): array
    {
        $base = BigDecimal::of(self::text($baseQuantity))->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
        $fallback = [
            'unit' => $this->baseUnit($ingredient),
            'quantity' => (string) StockDecimal::format((string) $base, 0, StockDecimal::QUANTITY_SCALE),
            'entered' => false,
        ];
        if ($enteredUnit === null || $enteredUnit === '' || $enteredQuantity === null || $enteredQuantity === '') {
            return $fallback;
        }

        try {
            $token = $this->token($ingredient, $enteredUnit);
            $factor = $this->factor($ingredient, $token);
        } catch (Throwable) {
            return $fallback; // the unit no longer exists for this ingredient
        }

        $entered = BigDecimal::of(self::text($enteredQuantity));
        if (! $entered->multipliedBy($factor)->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP)->isEqualTo($base)) {
            return $fallback; // the unit was re-sized since: show what is really stored
        }

        return [
            'unit' => $token,
            'quantity' => (string) StockDecimal::format((string) $entered->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP), 0, StockDecimal::QUANTITY_SCALE),
            'entered' => true,
        ];
    }

    /**
     * The normalised "as entered" key of a line for change detection: a line
     * stored before P3 (NULL entered columns) equals the same amount typed in
     * the base unit, so re-saving an untouched old recipe is a no-op.
     */
    public static function enteredKey(string $baseUnit, string|int|float $baseQuantity, ?string $enteredUnit, string|int|float|null $enteredQuantity): string
    {
        if ($enteredUnit === null || $enteredUnit === '' || $enteredQuantity === null || $enteredQuantity === '') {
            $enteredUnit = $baseUnit;
            $enteredQuantity = $baseQuantity;
        }

        return self::trim(BigDecimal::of(self::text($enteredQuantity))).' '.$enteredUnit;
    }

    /** A user-facing label for a unit token ('@piece' → the piece label). */
    public function label(Ingredient $ingredient, ?string $token): string
    {
        if ($token === IngredientUnitConverter::PIECE_UNIT) {
            return $ingredient->piece_unit_label ?? 'piece';
        }

        return ($token === null || $token === '') ? $this->baseUnit($ingredient) : $token;
    }

    /** '' / NULL / the base unit → the base unit's name; anything else as given. */
    public function token(Ingredient $ingredient, ?string $unit): string
    {
        $unit = $unit === null ? '' : trim($unit);

        return $unit === '' ? $this->baseUnit($ingredient) : $unit;
    }

    private function baseUnit(Ingredient $ingredient): string
    {
        return (string) ($ingredient->unit?->value ?? '');
    }

    /**
     * Base units per ONE of the token — the converter's resolution order
     * (base → piece → extra unit → metric pair), reading an eager-loaded
     * altUnits relation when present so a list of lines costs no queries.
     */
    private function factor(Ingredient $ingredient, string $token): BigDecimal
    {
        if ($token === $this->baseUnit($ingredient)) {
            return BigDecimal::one();
        }
        if ($token !== IngredientUnitConverter::PIECE_UNIT && $ingredient->relationLoaded('altUnits')) {
            $alt = $ingredient->altUnits->firstWhere('name', $token);
            if ($alt !== null) {
                if ((float) $alt->factor <= 0) {
                    throw new RuntimeException("Unit '{$token}' has an invalid (non-positive) conversion factor.");
                }

                return BigDecimal::of((string) $alt->factor);
            }
            $siblings = $ingredient->unit?->metricSiblings() ?? [];
            if (isset($siblings[$token])) {
                return self::decimal($siblings[$token]);
            }

            throw new RuntimeException("Unit '{$token}' is not defined for this ingredient.");
        }

        return self::decimal($this->units->factorFor($ingredient, $token));
    }

    /** "5.0000" → "5", "0.0500" → "0.05". */
    public static function trim(BigDecimal $value): string
    {
        $text = (string) $value;
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    private static function decimal(float $factor): BigDecimal
    {
        // Factors are metric powers of ten or numeric(14,4) values: exact at 10 places.
        return BigDecimal::of(rtrim(rtrim(number_format($factor, 10, '.', ''), '0'), '.'));
    }

    private static function text(string|int|float $value): string
    {
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') ?: '0';
        }
        $text = trim((string) $value);

        return $text === '' ? '0' : $text;
    }
}

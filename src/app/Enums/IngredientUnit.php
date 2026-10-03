<?php

declare(strict_types=1);

namespace App\Enums;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Phase 5a — units in which an ingredient's quantity is measured.
 *
 * Per blueprint §5.6.1. Stored as the lowercase string value on
 * pos_ingredients.unit and used implicitly on every stock_movement
 * for the same ingredient (the unit doesn't change once set).
 *
 * Conversions across units (e.g. kg <-> g) are intentionally NOT
 * provided at the DB layer — the merchant picks one unit per
 * ingredient and sticks with it. If they buy milk in litres and
 * a recipe needs millilitres, that's the merchant's bookkeeping.
 * Future versions could add a conversion helper if requested.
 */
enum IngredientUnit: string
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Litre = 'l';
    case Millilitre = 'ml';
    case Piece = 'piece';
    case Pack = 'pack';
    case Box = 'box';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * LAUNCH item kind (owner decision 2026-10-03) — what kind of item an
     * ingredient stored in this unit is: g/kg 'weighed', ml/l 'liquid',
     * piece/pack/box 'counted'. Read from the stored unit; no data converts.
     */
    public function kind(): string
    {
        return match ($this) {
            self::Kilogram, self::Gram => 'weighed',
            self::Litre, self::Millilitre => 'liquid',
            default => 'counted',
        };
    }

    /**
     * LAUNCH item kind — how many of THIS (stored) unit one $unit of the same
     * kind holds: 1 for the unit itself, the metric factor for its pair
     * (stored g: kg → 1000; stored kg: g → 0.001), null for any other unit.
     * What a pack size or an amount may be typed in.
     */
    public function factorOf(string $unit): ?float
    {
        if ($unit === $this->value) {
            return 1.0;
        }

        return $this->convertibleUnits()[$unit] ?? null;
    }

    /**
     * The units of this unit's kind, big first: ['kg', 'g', 'lb', 'oz'],
     * ['l', 'ml', 'gal', 'fl oz'] (G1), or the stored count unit alone
     * (['piece']).
     *
     * @return list<string>
     */
    public function kindUnits(): array
    {
        return match ($this->kind()) {
            'weighed' => [self::Kilogram->value, self::Gram->value, ...array_keys(self::NON_METRIC['mass'])],
            'liquid' => [self::Litre->value, self::Millilitre->value, ...array_keys(self::NON_METRIC['volume'])],
            default => [$this->value],
        };
    }

    /**
     * LAUNCH item kind, G1 (owner addendum 2026-10-03) — the US units every
     * Weighed / Liquid item also accepts, sized EXACTLY in its family's
     * canonical base: US gallon and US fluid ounce in ml, avoirdupois pound
     * and ounce in g. Stored units never change.
     */
    private const NON_METRIC = [
        'mass' => ['lb' => '453.59237', 'oz' => '28.349523125'],
        'volume' => ['gal' => '3785.411784', 'fl oz' => '29.5735295625'],
    ];

    /**
     * Every unit besides the stored one that an amount of this item may be
     * typed in, mapped to its factor = stored units per ONE of that unit: the
     * metric pair (PD4) and, G1, the US pair. Empty for count units.
     * e.g. stored g → ['kg' => 1000, 'lb' => 453.59237, 'oz' => 28.349523125].
     *
     * @return array<string, float>
     */
    public function convertibleUnits(): array
    {
        return array_map(static fn (string $factor): float => (float) $factor, $this->convertibleUnitFactors());
    }

    /**
     * {@see convertibleUnits()} as EXACT decimal strings ("3.785411784" l per
     * gallon, "0.0295735295625" l per fl oz), for the API and the recipe
     * arithmetic, where a float would print as 3.7854117839999999.
     *
     * @return array<string, string>
     */
    public function convertibleUnitFactors(): array
    {
        $family = $this->family();
        $baseMagnitude = $this->magnitude();
        if ($family === null || $baseMagnitude === null) {
            return [];
        }
        $base = BigDecimal::of((string) (int) $baseMagnitude);
        $ratio = static fn (string $size): string => (string) BigDecimal::of($size)
            ->dividedBy($base, 15, RoundingMode::HALF_UP)
            ->stripTrailingZeros();

        $factors = [];
        foreach (self::cases() as $case) {
            if ($case === $this || $case->family() !== $family) {
                continue;
            }
            $factors[$case->value] = $ratio((string) (int) $case->magnitude());
        }
        foreach (self::NON_METRIC[$family] as $name => $size) {
            $factors[$name] = $ratio($size);
        }

        return $factors;
    }

    /**
     * PD4 — the metric family this unit belongs to, or null for count units
     * (piece/pack/box) whose subdivisions are not universal. Only units in the
     * SAME family auto-convert (kg <-> g, l <-> ml); cross-family (kg <-> l)
     * needs density and stays a custom alternate unit.
     */
    public function family(): ?string
    {
        return match ($this) {
            self::Kilogram, self::Gram => 'mass',
            self::Litre, self::Millilitre => 'volume',
            default => null,
        };
    }

    /**
     * Size of ONE of this unit in its family's canonical base (grams for mass,
     * millilitres for volume). Null when the unit is not metric.
     */
    private function magnitude(): ?float
    {
        return match ($this) {
            self::Kilogram => 1000.0,
            self::Gram => 1.0,
            self::Litre => 1000.0,
            self::Millilitre => 1.0,
            default => null,
        };
    }

    /**
     * PD4 — the same-family metric units the system provides AUTOMATICALLY for
     * an ingredient whose base unit is $this (the base itself excluded), each
     * mapped to its factor = base units per ONE of that unit. Empty for count
     * units. e.g. base kg -> ['g' => 0.001]; base g -> ['kg' => 1000.0].
     *
     * @return array<string, float>
     */
    public function metricSiblings(): array
    {
        $family = $this->family();
        $baseMagnitude = $this->magnitude();
        if ($family === null || $baseMagnitude === null) {
            return [];
        }

        $siblings = [];
        foreach (self::cases() as $case) {
            if ($case === $this || $case->family() !== $family) {
                continue;
            }
            $siblings[$case->value] = $case->magnitude() / $baseMagnitude;
        }

        return $siblings;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

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

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
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6 backfill — expense category (blueprint §5.10).
 *
 * The fixed category set the POS expense-logging screen offers.
 * Stored as the lowercase string on pos_expenses.category.
 */
enum ExpenseCategory: string
{
    case Utilities = 'utilities';
    case Supplies = 'supplies';
    case Maintenance = 'maintenance';
    case Salaries = 'salaries';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

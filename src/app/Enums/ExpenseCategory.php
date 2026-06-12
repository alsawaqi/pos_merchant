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
    // Ingredient / inventory purchases. Auto-logged by RestockAction
    // (a restock with a cost) + selectable for a manual ingredient buy.
    case Ingredients = 'ingredients';
    // PD2 — bought-in (ready / unit) goods purchases, auto-logged when a
    // stock receive carries a cost. Unlike 'ingredients' this COUNTS in
    // operating expenses: unit products have no recipe snapshot, so
    // their sales contribute zero COGS — the purchase expense is the
    // only place their cost can reach net profit.
    case StockPurchases = 'stock_purchases';
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

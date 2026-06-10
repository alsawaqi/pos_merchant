<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5c — closed enum of waste reasons (blueprint §5.6.4).
 *
 * Categorises why stock was wasted. The Waste tab + by-reason
 * report aggregate on this column, so it must be a fixed
 * vocabulary the merchant chooses from rather than free text.
 *
 * 'Other' is the escape hatch for events that don't fit the
 * other buckets — Action validation requires the notes field
 * to be populated when reason=other so we still capture WHY.
 *
 * Adding a new reason needs:
 *   - a new case here
 *   - an i18n key in catalogue/inventory translations
 *   - no migration (column is a 32-char string)
 */
enum WasteReason: string
{
    case Expired = 'expired';
    case Spoiled = 'spoiled';
    case Broken = 'broken';
    case Dropped = 'dropped';
    case Contamination = 'contamination';
    // Phase A (Additions §2.8) — written by the day-end stock count when the
    // physical count comes in BELOW the running balance. Never picked manually
    // in the Waste form; only SubmitStockCountAction emits it.
    case ReconciliationVariance = 'reconciliation_variance';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

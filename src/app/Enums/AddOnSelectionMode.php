<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 4.9 — single-vs-multi selection within an add-on group.
 *
 * Drives both the POS UX (radio vs checkbox) and any future
 * order-validation rule that enforces "exactly one milk choice
 * per latte". Persisted as the lowercase string value.
 */
enum AddOnSelectionMode: string
{
    case Single = 'single';
    case Multi = 'multi';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — order source (which device family wrote it).
 *
 *   main_pos          — fixed POS terminal at the counter
 *   handheld          — handheld waiter device (§6 Handheld
 *                       POS Application)
 *   customer_tablet   — customer-facing kiosk / drive-thru
 *                       tablet (§8 Customer Tablet)
 *
 * Used by §5.11.10 Staff Activity Report breakdown + the
 * device-health observability dashboard.
 */
enum OrderSource: string
{
    case MainPos = 'main_pos';
    case Handheld = 'handheld';
    case CustomerTablet = 'customer_tablet';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P-G8 — the unit a branch sales target is expressed in. A target of
 * 200/day with a 3-period window evaluates 600 cumulative per 3-day
 * window; weeks are 7-day blocks from starts_on (not calendar weeks),
 * months advance calendar-wise without overflow (a Jan-31 anchor never
 * skips February).
 */
enum BranchTargetPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

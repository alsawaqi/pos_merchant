<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — POS shift status (blueprint §10.8).
 *
 *   open    — cashier opened the shift with a float;
 *             orders rung up since opened_at link to it
 *   closed  — cashier (or supervisor) closed the shift
 *             with a cash count; variance computed
 */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

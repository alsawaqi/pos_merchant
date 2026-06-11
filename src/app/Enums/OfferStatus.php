<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P-F9 — offer lifecycle. Unlike discounts there is no `expired`
 * status — expiry is purely the validity-window predicate; the
 * merchant lever is just active ⇄ paused.
 */
enum OfferStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

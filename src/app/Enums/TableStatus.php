<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's TableStatus. Persistent admin state
 * (active/inactive); operational states like
 * occupied/dirty/reserved are derived from live order data
 * at the POS device.
 */
enum TableStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

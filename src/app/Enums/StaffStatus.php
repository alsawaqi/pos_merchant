<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's StaffStatus. Identical contract — see
 * the pos_admin enum for the lifecycle commentary.
 */
enum StaffStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

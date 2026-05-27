<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's ProductStatus.
 */
enum ProductStatus: string
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

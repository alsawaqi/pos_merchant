<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's TableShape. Application-side
 * whitelist; column on the DB is free-form string so new
 * shapes can land without a migration.
 */
enum TableShape: string
{
    case Round = 'round';
    case Square = 'square';
    case Rectangle = 'rectangle';
    case Oval = 'oval';
    case Counter = 'counter';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — rule lifecycle status (blueprint §5.8:
 * "pause / resume any rule without deleting it").
 *
 *   active — eligible for application at POS time.
 *   paused — temporarily disabled; resumable without re-creating.
 */
enum LoyaltyRuleStatus: string
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

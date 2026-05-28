<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6d — Discount lifecycle status.
 *
 *   active   — eligible for application by the evaluator
 *   paused   — merchant has temporarily disabled it; can be
 *              resumed without re-creating
 *   expired  — past validity_end; computed by a daily
 *              maintenance job (not strictly required since
 *              the evaluator's validity-window predicate
 *              already excludes expired rules)
 */
enum DiscountStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

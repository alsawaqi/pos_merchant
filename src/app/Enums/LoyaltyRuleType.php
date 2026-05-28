<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — rule mechanic (blueprint §5.8).
 *
 *   visit_based — stamp card: each qualifying order earns a stamp;
 *                 N stamps unlock a reward.
 *   spend_based — points: earn points per OMR spent; redeem points
 *                 for OMR off.
 *
 * Both can run in parallel (a company may have several rules of
 * each type active at once).
 */
enum LoyaltyRuleType: string
{
    case VisitBased = 'visit_based';
    case SpendBased = 'spend_based';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

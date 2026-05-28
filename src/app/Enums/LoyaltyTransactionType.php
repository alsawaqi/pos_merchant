<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Loyalty refactor — transaction kind (blueprint §10.6).
 *
 *   earn   — points/stamps awarded by a qualifying sale (Phase 8).
 *   redeem — points/stamps consumed for a reward (Phase 8).
 *   adjust — manual correction by a portal user (signed).
 *   expire — points/stamps lapsed by an expiry job.
 *
 * Phase 6b's refactor emits `adjust` for the manual path; the POS
 * sale pipeline (Phase 8) emits earn + redeem.
 */
enum LoyaltyTransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjust = 'adjust';
    case Expire = 'expire';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

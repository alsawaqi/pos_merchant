<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6b — closed enum for pos_customer_point_ledger.entry_type.
 *
 * Catalogue:
 *   earn        — Phase 8 POS sale pays out points per the
 *                  loyalty config's points_per_omr rate
 *   redeem      — Phase 8 customer applies points at checkout
 *                  to get baisas_per_point off the bill
 *   adjustment  — manual admin add/remove (Phase 6b ships this)
 *   refund_in   — Phase 7+ order refund returns points
 *   expiry      — future background job that ages out unused
 *                  points after N months
 *
 * Phase 6b actions only emit 'adjustment'; the rest arrive
 * with later phases. The enum is closed so a typo'd or
 * future-only value fails at write time, not silently in a
 * report.
 */
enum PointLedgerEntryType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjustment = 'adjustment';
    case RefundIn = 'refund_in';
    case Expiry = 'expiry';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

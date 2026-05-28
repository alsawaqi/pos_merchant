<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6b — closed enum for pos_customer_wallet_ledger.entry_type.
 *
 * Catalogue:
 *   topup           — manual admin top-up: "received 5 OMR
 *                      cash, credit the wallet". Always positive.
 *                      Phase 6b ships this.
 *   redemption_use  — Phase 8 customer applies wallet at POS.
 *                      Always negative (outflow).
 *   adjustment      — manual admin correction. SIGNED — can be
 *                      positive or negative.
 *                      Phase 6b ships this.
 *   refund_in       — Phase 7+ order refund flows here instead
 *                      of back-to-cash. Always positive.
 */
enum WalletLedgerEntryType: string
{
    case TopUp = 'topup';
    case RedemptionUse = 'redemption_use';
    case Adjustment = 'adjustment';
    case RefundIn = 'refund_in';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

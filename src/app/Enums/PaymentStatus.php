<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — payment status (blueprint §10.8 + §16 Soft POS).
 *
 *   success                   — captured cleanly. The default
 *                                for cash. Card payments reach
 *                                this after the Soft POS auth
 *                                code returns
 *   pending_reconciliation    — card payment recorded offline;
 *                                the cashier completes the
 *                                actual bank charge on reconnect
 *                                and an admin matches it against
 *                                the settlement file (§16)
 *   failed                    — bank refused, NFC timeout, etc.
 */
enum PaymentStatus: string
{
    case Success = 'success';
    case PendingReconciliation = 'pending_reconciliation';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

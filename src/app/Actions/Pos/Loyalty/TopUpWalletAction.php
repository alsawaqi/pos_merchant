<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Enums\WalletLedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\User;
use RuntimeException;

/**
 * Phase 6b — manual wallet top-up.
 *
 * Separate from AdjustWalletBalanceAction because:
 *   - top-up amounts are ALWAYS positive (semantically) — the
 *     wrapper enforces it explicitly so the controller layer
 *     doesn't need its own sign-check
 *   - the entry_type is TopUp (different category in reports
 *     than an Adjustment, even though both are positive
 *     inflows)
 *   - reason is optional here ("cash topup at counter" is the
 *     default mental model; a specific reason is only needed
 *     when the source is unusual)
 */
final readonly class TopUpWalletAction
{
    public function __construct(
        private WriteWalletLedgerEntryAction $writeEntry,
    ) {}

    public function handle(
        Customer $customer,
        string|float|int $amount,
        User $actor,
        ?string $reason = null,
    ): CustomerWalletLedgerEntry {
        if ((float) $amount <= 0) {
            throw new RuntimeException('Top-up amount must be positive.');
        }
        return $this->writeEntry->handle(
            customer: $customer,
            entryType: WalletLedgerEntryType::TopUp,
            amountDelta: $amount,
            actor: $actor,
            reason: $reason,
        );
    }
}

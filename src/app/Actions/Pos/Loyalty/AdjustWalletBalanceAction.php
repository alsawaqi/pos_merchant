<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Enums\WalletLedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\User;

/**
 * Phase 6b — thin wrapper for the merchant's "manually adjust
 * wallet balance" UI flow.
 *
 * Delegates to WriteWalletLedgerEntryAction with entry_type
 * fixed to Adjustment. SIGNED — positive to credit, negative
 * to debit. Reason required.
 */
final readonly class AdjustWalletBalanceAction
{
    public function __construct(
        private WriteWalletLedgerEntryAction $writeEntry,
    ) {}

    public function handle(
        Customer $customer,
        string|float|int $amountDelta,
        User $actor,
        string $reason,
    ): CustomerWalletLedgerEntry {
        return $this->writeEntry->handle(
            customer: $customer,
            entryType: WalletLedgerEntryType::Adjustment,
            amountDelta: $amountDelta,
            actor: $actor,
            reason: $reason,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Enums\PointLedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerPointLedgerEntry;
use App\Models\User;

/**
 * Phase 6b — thin wrapper for the merchant's "manually adjust
 * point balance" UI flow.
 *
 * Delegates to WritePointLedgerEntryAction with entry_type
 * fixed to Adjustment. Reason is required by the underlying
 * action; the controller surfaces the 422 if the caller
 * forgets.
 *
 * Signed delta: positive to add, negative to subtract. Going
 * below zero is refused by the underlying action.
 */
final readonly class AdjustPointBalanceAction
{
    public function __construct(
        private WritePointLedgerEntryAction $writeEntry,
    ) {}

    public function handle(
        Customer $customer,
        int $pointsDelta,
        User $actor,
        string $reason,
    ): CustomerPointLedgerEntry {
        return $this->writeEntry->handle(
            customer: $customer,
            entryType: PointLedgerEntryType::Adjustment,
            pointsDelta: $pointsDelta,
            actor: $actor,
            reason: $reason,
        );
    }
}

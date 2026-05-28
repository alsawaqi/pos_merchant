<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\WalletLedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6b — canonical entry point for ANY change to a
 * customer's wallet balance.
 *
 * Same structure + invariants as WritePointLedgerEntryAction;
 * differs only in the value type (decimal:3 OMR vs integer
 * points). See that class for the full rationale.
 *
 * Refusing to go negative: a redemption_use / adjustment-down
 * that would drive the wallet below zero throws.
 */
final readonly class WriteWalletLedgerEntryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        Customer $customer,
        WalletLedgerEntryType $entryType,
        string|float|int $amountDelta,
        User $actor,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?DateTimeInterface $occurredAt = null,
    ): CustomerWalletLedgerEntry {
        $companyId = $this->tenant->requiredId();

        if ((int) $customer->company_id !== $companyId) {
            throw new RuntimeException('Customer does not belong to your company.');
        }

        // Normalise to a string with 3-decimal precision once,
        // then compare against zero by casting through float.
        // OMR baisas precision means a delta of 0.000 must
        // round-trip through the same coercion path the DB
        // applies on the way back, so we use number_format with
        // 3 decimals as the canonical form for both sides.
        $deltaString = number_format((float) $amountDelta, 3, '.', '');
        $deltaFloat = (float) $deltaString;
        if ($deltaFloat === 0.0) {
            throw new RuntimeException('Wallet delta cannot be zero — the ledger only carries real changes.');
        }

        if ($entryType === WalletLedgerEntryType::Adjustment && trim((string) $reason) === '') {
            throw new RuntimeException('A reason is required when adjusting a wallet balance manually.');
        }

        // Top-ups must be positive (you can't "top up" by a
        // negative amount — that's an adjustment). Same for
        // redemption_use (must be negative, an outflow) and
        // refund_in (must be positive, an inflow). The entry
        // type IS the signal here, so the action enforces it.
        if ($entryType === WalletLedgerEntryType::TopUp && $deltaFloat < 0) {
            throw new RuntimeException('Wallet top-up amount must be positive.');
        }
        if ($entryType === WalletLedgerEntryType::RedemptionUse && $deltaFloat > 0) {
            throw new RuntimeException('Wallet redemption_use amount must be negative (outflow).');
        }
        if ($entryType === WalletLedgerEntryType::RefundIn && $deltaFloat < 0) {
            throw new RuntimeException('Wallet refund_in amount must be positive.');
        }

        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? Carbon::instance($occurredAt)
            : now();

        return DB::transaction(function () use (
            $customer,
            $entryType,
            $deltaString,
            $deltaFloat,
            $actor,
            $reason,
            $referenceType,
            $referenceId,
            $occurredAt,
            $companyId,
        ): CustomerWalletLedgerEntry {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $currentBalance = (float) $locked->wallet_balance;
            $newBalance = $currentBalance + $deltaFloat;
            // Re-stringify with 3-decimal precision to match the
            // DB's storage form.
            $newBalanceString = number_format($newBalance, 3, '.', '');

            if ($newBalance < 0) {
                throw new RuntimeException(sprintf(
                    'Insufficient wallet balance — balance is %s but the change would drop it to %s.',
                    number_format($currentBalance, 3, '.', ''),
                    $newBalanceString,
                ));
            }

            /** @var CustomerWalletLedgerEntry $entry */
            $entry = CustomerWalletLedgerEntry::query()->create([
                'customer_id' => $locked->id,
                'company_id' => $companyId,
                'entry_type' => $entryType->value,
                'amount_delta' => $deltaString,
                'balance_after' => $newBalanceString,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $locked->forceFill(['wallet_balance' => $newBalanceString])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.wallet_ledger.entry',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CustomerWalletLedgerEntry::class,
                auditableId: $entry->id,
                newValues: [
                    'customer_id' => $locked->id,
                    'entry_type' => $entryType->value,
                    'amount_delta' => $deltaString,
                    'balance_after' => $newBalanceString,
                    'reason' => $reason,
                ],
            ));

            return $entry->fresh();
        });
    }
}

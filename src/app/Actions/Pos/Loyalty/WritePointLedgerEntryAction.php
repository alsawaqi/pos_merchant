<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PointLedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerPointLedgerEntry;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6b — canonical entry point for ANY change to a
 * customer's points balance.
 *
 * Every other loyalty Action delegates here. Centralising the
 * write means three invariants hold everywhere:
 *
 *   1. Customer belongs to the actor's company — cross-tenant
 *      mutation is impossible.
 *   2. pos_customers.points_balance stays in lock-step with
 *      SUM(pos_customer_point_ledger.points_delta) per customer.
 *      Wrapped in DB::transaction so both writes commit or
 *      neither does.
 *   3. balance_after on the ledger row is the running total
 *      AFTER this entry landed. Lets the history view skip the
 *      per-row re-sum, and catches ledger-balance drift
 *      instantly (last entry's balance_after MUST equal the
 *      customer's points_balance).
 *
 * SIGNED delta: positive on earn / adjustment-up / refund_in,
 * negative on redeem / adjustment-down / expiry. The action
 * does NOT enforce sign per entry_type — that's the caller's
 * (typed) responsibility. We DO refuse a delta of zero (the
 * ledger doesn't carry no-op entries).
 *
 * Refusing to go negative: a redeem / adjustment-down that
 * would drive the balance below zero throws. The customer-
 * facing UX should validate before submit, but the action is
 * the source of truth and never lets the books go upside down.
 */
final readonly class WritePointLedgerEntryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        Customer $customer,
        PointLedgerEntryType $entryType,
        int $pointsDelta,
        User $actor,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?DateTimeInterface $occurredAt = null,
    ): CustomerPointLedgerEntry {
        $companyId = $this->tenant->requiredId();

        // Defence in depth — even though callers resolve customer
        // via a tenant-scoped query, we re-check here.
        if ((int) $customer->company_id !== $companyId) {
            throw new RuntimeException('Customer does not belong to your company.');
        }

        if ($pointsDelta === 0) {
            throw new RuntimeException('Points delta cannot be zero — the ledger only carries real changes.');
        }

        // Manual adjustments require a non-empty reason. Without
        // one the audit trail loses meaning ("someone changed
        // a balance by 50 — why?"). Phase 8+ earn/redeem
        // entries derive their context from the reference, so
        // reason is optional for those.
        if ($entryType === PointLedgerEntryType::Adjustment && trim((string) $reason) === '') {
            throw new RuntimeException('A reason is required when adjusting a points balance manually.');
        }

        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? Carbon::instance($occurredAt)
            : now();

        return DB::transaction(function () use (
            $customer,
            $entryType,
            $pointsDelta,
            $actor,
            $reason,
            $referenceType,
            $referenceId,
            $occurredAt,
            $companyId,
        ): CustomerPointLedgerEntry {
            // Re-read the customer inside the transaction with a
            // lock so concurrent writers can't both observe the
            // same balance + race a double-spend. SELECT ... FOR
            // UPDATE on Postgres serialises this row across the
            // transaction.
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $currentBalance = (int) $locked->points_balance;
            $newBalance = $currentBalance + $pointsDelta;

            if ($newBalance < 0) {
                throw new RuntimeException(sprintf(
                    'Insufficient points — balance is %d but the change would drop it to %d.',
                    $currentBalance,
                    $newBalance,
                ));
            }

            // Step 1: append the ledger row. Never updated, never
            // deleted — corrections are NEW Adjustment rows.
            /** @var CustomerPointLedgerEntry $entry */
            $entry = CustomerPointLedgerEntry::query()->create([
                'customer_id' => $locked->id,
                'company_id' => $companyId,
                'entry_type' => $entryType->value,
                'points_delta' => $pointsDelta,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            // Step 2: bump the denormalised running total. We
            // could use $locked->increment(), but we already
            // computed the new value so an explicit assignment
            // is clearer.
            $locked->forceFill(['points_balance' => $newBalance])->save();

            // Step 3: audit row. Distinct from the ledger row —
            // the ledger is the accounting record, this is the
            // security/forensics record. Both append-only.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.point_ledger.entry',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CustomerPointLedgerEntry::class,
                auditableId: $entry->id,
                newValues: [
                    'customer_id' => $locked->id,
                    'entry_type' => $entryType->value,
                    'points_delta' => $pointsDelta,
                    'balance_after' => $newBalance,
                    'reason' => $reason,
                ],
            ));

            return $entry->fresh();
        });
    }
}

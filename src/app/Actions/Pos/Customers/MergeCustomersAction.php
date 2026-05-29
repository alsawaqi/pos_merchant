<?php

declare(strict_types=1);

namespace App\Actions\Pos\Customers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6a — merge a duplicate customer (the "source") into a survivor.
 *
 * Re-points everything the source owns onto the survivor, folds the loyalty
 * and wallet balances together, then soft-deletes the source. One audit row
 * ('customers.merged') records what moved.
 *
 *   - orders         → re-pointed (customer_id).
 *   - vehicle plates → re-pointed; a plate the survivor ALREADY has (unique
 *     company_id + plate_number) is dropped instead, to avoid a clash.
 *   - loyalty        → per loyalty_rule_id: if the survivor already has an
 *     account for that rule, the source's stamp_count + point_balance are
 *     summed in, its transactions re-pointed to the survivor's account, and
 *     the source account removed; otherwise the whole account moves across.
 *   - wallet         → ledger entries re-pointed and the survivor's
 *     denormalised wallet_balance bumped by the source's (the running fold
 *     stays correct). Each moved entry keeps its historical
 *     wallet_balance_after — those are point-in-time values, not recomputed.
 *
 * Both customers must belong to the actor's company; a customer cannot be
 * merged into itself. Soft delete only — Phase 7+ orders reference
 * customer_id and historical reports must not break.
 */
final readonly class MergeCustomersAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array{customer: Customer, summary: array<string, int>}
     */
    public function handle(Customer $survivor, Customer $source, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        foreach ([$survivor, $source] as $customer) {
            if ((int) $customer->company_id !== $companyId) {
                throw new RuntimeException('Customer does not belong to your company.');
            }
        }
        if ((int) $survivor->id === (int) $source->id) {
            throw new RuntimeException('A customer cannot be merged into itself.');
        }

        return DB::transaction(function () use ($survivor, $source, $actor, $companyId): array {
            $summary = [
                'orders_moved' => 0,
                'plates_moved' => 0,
                'loyalty_accounts_moved' => 0,
                'loyalty_accounts_merged' => 0,
                'wallet_entries_moved' => 0,
            ];

            // --- Orders ---
            $summary['orders_moved'] = Order::query()
                ->where('company_id', $companyId)
                ->where('customer_id', $source->id)
                ->update(['customer_id' => $survivor->id]);

            // --- Vehicle plates ---
            // (company_id, plate_number) is unique, so two customers in one
            // company can never share a plate — the source's plates can't
            // collide with the survivor's. A straight re-point is safe.
            $summary['plates_moved'] = CustomerVehiclePlate::query()
                ->where('company_id', $companyId)
                ->where('customer_id', $source->id)
                ->update(['customer_id' => $survivor->id]);

            // --- Loyalty accounts (unique per loyalty_rule_id) ---
            $survivorAccounts = $survivor->loyaltyAccounts()->get()->keyBy('loyalty_rule_id');
            foreach ($source->loyaltyAccounts()->get() as $sourceAccount) {
                $target = $survivorAccounts->get($sourceAccount->loyalty_rule_id);

                if ($target !== null) {
                    // Survivor already engages this rule — fold the balances in
                    // and re-point the source account's ledger to the survivor's
                    // account. Transactions link to the customer only via the
                    // account, so there is no customer_id on them to touch.
                    $target->stamp_count += $sourceAccount->stamp_count;
                    $target->point_balance += $sourceAccount->point_balance;
                    $target->last_activity_at = $this->laterOf($target->last_activity_at, $sourceAccount->last_activity_at);
                    $target->save();

                    LoyaltyTransaction::query()
                        ->where('loyalty_account_id', $sourceAccount->id)
                        ->update(['loyalty_account_id' => $target->id]);

                    $sourceAccount->delete();
                    $summary['loyalty_accounts_merged']++;
                } else {
                    // Survivor has no account for this rule — move the whole
                    // account across; its transactions follow via loyalty_account_id.
                    $sourceAccount->update(['customer_id' => $survivor->id]);
                    $survivorAccounts->put($sourceAccount->loyalty_rule_id, $sourceAccount);
                    $summary['loyalty_accounts_moved']++;
                }
            }

            // --- Wallet (denormalised balance is the fold of the ledger) ---
            $summary['wallet_entries_moved'] = CustomerWalletLedgerEntry::query()
                ->where('company_id', $companyId)
                ->where('customer_id', $source->id)
                ->update(['customer_id' => $survivor->id]);
            $survivor->wallet_balance = number_format(
                (float) $survivor->wallet_balance + (float) $source->wallet_balance, 3, '.', '',
            );
            $source->wallet_balance = '0.000';
            $survivor->save();
            $source->save();

            // --- Audit + retire the source ---
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.merged',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Customer::class,
                auditableId: $survivor->id,
                newValues: array_merge($summary, [
                    'source_customer_id' => $source->id,
                    'source_customer_phone' => $source->phone,
                ]),
            ));

            $source->delete();

            return ['customer' => $survivor->fresh(), 'summary' => $summary];
        });
    }

    private function laterOf(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}

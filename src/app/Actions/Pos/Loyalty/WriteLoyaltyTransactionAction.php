<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loyalty refactor — the single atomic writer for the loyalty
 * ledger. Replaces the Phase 6b WritePointLedgerEntryAction.
 *
 * Locks the account row (SELECT … FOR UPDATE), computes the new
 * running balances, inserts the append-only transaction with the
 * post-application balance_after_* snapshots, then updates the
 * account's denormalised balances + last_activity_at — all inside
 * one DB transaction so account ≡ SUM(transactions) can never
 * drift.
 *
 * A row may move points, stamps, or both. Adjustments require a
 * reason. Balances may not go negative.
 *
 * recorded_by_user_id is the portal user behind a manual write;
 * the Phase 8 sale pipeline will instead pass an order_id with a
 * null actor.
 *
 * Audit event: loyalty.transaction.{type}.
 */
final readonly class WriteLoyaltyTransactionAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        LoyaltyAccount $account,
        LoyaltyTransactionType $type,
        int $pointsDelta,
        int $stampsDelta,
        User $actor,
        ?string $reason = null,
        ?int $orderId = null,
        ?DateTimeInterface $occurredAt = null,
    ): LoyaltyTransaction {
        $companyId = $this->tenant->requiredId();
        if ((int) $account->company_id !== $companyId) {
            throw new RuntimeException('Loyalty account does not belong to this company.');
        }
        if ($pointsDelta === 0 && $stampsDelta === 0) {
            throw new RuntimeException('A loyalty transaction must move points or stamps.');
        }
        if ($type === LoyaltyTransactionType::Adjust && ($reason === null || trim($reason) === '')) {
            throw new RuntimeException('A reason is required for a manual loyalty adjustment.');
        }

        return DB::transaction(function () use ($account, $type, $pointsDelta, $stampsDelta, $actor, $reason, $orderId, $occurredAt, $companyId): LoyaltyTransaction {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);

            $newPoints = (int) $locked->point_balance + $pointsDelta;
            $newStamps = (int) $locked->stamp_count + $stampsDelta;
            if ($newPoints < 0) {
                throw new RuntimeException('Point balance cannot go negative.');
            }
            if ($newStamps < 0) {
                throw new RuntimeException('Stamp count cannot go negative.');
            }

            /** @var LoyaltyTransaction $txn */
            $txn = LoyaltyTransaction::query()->create([
                'company_id' => $companyId,
                'loyalty_account_id' => $locked->id,
                'type' => $type->value,
                'points_delta' => $pointsDelta,
                'stamps_delta' => $stampsDelta,
                'balance_after_points' => $newPoints,
                'balance_after_stamps' => $newStamps,
                'reason' => $reason,
                'order_id' => $orderId,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $locked->update([
                'point_balance' => $newPoints,
                'stamp_count' => $newStamps,
                'last_activity_at' => now(),
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.transaction.' . $type->value,
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyTransaction::class,
                auditableId: $txn->id,
                newValues: [
                    'points_delta' => $pointsDelta,
                    'stamps_delta' => $stampsDelta,
                    'balance_after_points' => $newPoints,
                    'balance_after_stamps' => $newStamps,
                ],
            ));

            return $txn->fresh();
        });
    }
}

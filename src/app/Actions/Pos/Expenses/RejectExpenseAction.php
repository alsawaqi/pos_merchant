<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6 backfill — reject an expense with a required reason
 * (blueprint §5.10). A rejected expense is EXCLUDED from the
 * net-profit rollup but retained for the audit trail (no delete).
 *
 * Allowed from `recorded` or `reviewed` (a manager can reverse a
 * previously-approved expense). Re-rejecting a rejected one is a
 * no-op error.
 *
 * Audit event: expense.rejected.
 */
final readonly class RejectExpenseAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Expense $expense, User $actor, string $reviewNote): Expense
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $expense->company_id !== $companyId) {
            throw new RuntimeException('Expense does not belong to this company.');
        }
        if ($expense->status === ExpenseStatus::Rejected) {
            throw new RuntimeException('Expense is already rejected.');
        }

        $reason = trim($reviewNote);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($expense, $actor, $reason, $companyId): Expense {
            $before = $expense->status->value;

            $expense->update([
                'status' => ExpenseStatus::Rejected->value,
                'reviewed_by_portal_user_id' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'expense.rejected',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: (int) $expense->branch_id,
                auditableType: Expense::class,
                auditableId: $expense->id,
                oldValues: ['status' => $before],
                newValues: ['status' => ExpenseStatus::Rejected->value, 'review_note' => $reason],
            ));

            return $expense->fresh();
        });
    }
}

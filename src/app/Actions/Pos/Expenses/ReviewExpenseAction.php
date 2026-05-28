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
 * Phase 6 backfill — approve (review) an expense, optionally with
 * an annotation. Covers the blueprint's "approve" + "annotate"
 * verbs (§5.10): annotation = a review with a note.
 *
 * Allowed from `recorded` or `reviewed` (re-annotating an
 * already-approved expense is fine + idempotent). NOT allowed
 * from `rejected` — a rejected expense must be re-logged.
 *
 * Audit event: expense.reviewed.
 */
final readonly class ReviewExpenseAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Expense $expense, User $actor, ?string $reviewNote = null): Expense
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $expense->company_id !== $companyId) {
            throw new RuntimeException('Expense does not belong to this company.');
        }
        if ($expense->status === ExpenseStatus::Rejected) {
            throw new RuntimeException('A rejected expense cannot be reviewed; it must be re-logged.');
        }

        return DB::transaction(function () use ($expense, $actor, $reviewNote, $companyId): Expense {
            $before = $expense->status->value;

            $expense->update([
                'status' => ExpenseStatus::Reviewed->value,
                'reviewed_by_portal_user_id' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reviewNote ?? $expense->review_note,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'expense.reviewed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: (int) $expense->branch_id,
                auditableType: Expense::class,
                auditableId: $expense->id,
                oldValues: ['status' => $before],
                newValues: ['status' => ExpenseStatus::Reviewed->value, 'review_note' => $reviewNote],
            ));

            return $expense->fresh();
        });
    }
}

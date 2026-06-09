<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6 backfill — log an expense from the back-office portal.
 *
 * The blueprint scopes expense CREATION to the POS device feed
 * (§5.10 "Captured from POS"), but until the POS app exists the
 * portal needs a create path so the review queue isn't empty.
 * This Action stamps logged_by_portal_user_id (NOT a pos_staff)
 * and starts the row in the `recorded` state.
 *
 * Validation:
 *   - branch belongs to the acting tenant
 *   - category in ExpenseCategory
 *   - amount > 0
 *
 * Audit event: expense.logged.
 */
final readonly class LogExpenseAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private EnsureDefaultExpenseCategoriesAction $ensureCategories,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Expense
    {
        $companyId = $this->tenant->requiredId();

        // A null / blank / 0 branch_id = a general / company-wide expense
        // (the controller casts an absent branch to 0). A real branch id is
        // always > 0; only then do we enforce tenant ownership.
        $branchId = (int) ($attributes['branch_id'] ?? 0);
        $branchId = $branchId > 0 ? $branchId : null;
        if ($branchId !== null) {
            $branchOwned = Branch::query()
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();
            if (! $branchOwned) {
                throw new RuntimeException('Branch does not belong to this company.');
            }
        }

        // v2 #7: category is a per-company key (slug) from pos_expense_categories.
        // Ensure the company's defaults exist, then require the submitted key to
        // be one of that company's ACTIVE categories.
        $this->ensureCategories->handle($companyId);
        $category = trim((string) ($attributes['category'] ?? ''));
        $categoryValid = ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->where('key', $category)
            ->where('is_active', true)
            ->exists();
        if (! $categoryValid) {
            throw new RuntimeException('Unknown expense category for this company.');
        }

        $amount = (float) $attributes['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('Expense amount must be positive.');
        }

        return DB::transaction(function () use ($attributes, $companyId, $branchId, $category, $amount, $actor): Expense {
            /** @var Expense $expense */
            $expense = Expense::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'category' => $category,
                'amount' => number_format($amount, 3, '.', ''),
                'note' => $attributes['note'] ?? null,
                'receipt_photo_path' => $attributes['receipt_photo_path'] ?? null,
                'logged_by_portal_user_id' => $actor->getKey(),
                'logged_at' => $attributes['logged_at'] ?? now(),
                'status' => ExpenseStatus::Recorded->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'expense.logged',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branchId,
                auditableType: Expense::class,
                auditableId: $expense->id,
                newValues: [
                    'category' => $category,
                    'amount' => (string) $expense->amount,
                    'branch_id' => $branchId,
                ],
            ));

            return $expense->fresh();
        });
    }
}

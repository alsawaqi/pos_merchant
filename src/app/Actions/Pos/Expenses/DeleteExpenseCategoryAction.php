<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a company expense category. The POS stops offering it; historical
 * pos_expenses keep their string key and the soft-deleted row stays resolvable,
 * so no hard guard is needed. Audit event: settings.expense_category.deleted.
 */
final readonly class DeleteExpenseCategoryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(ExpenseCategory $cat, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $cat->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($cat, $actor, $companyId): void {
            $categoryId = $cat->id;
            $snapshot = [
                'name' => $cat->name,
                'key' => $cat->key,
                'is_active' => (bool) $cat->is_active,
            ];

            $cat->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.expense_category.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ExpenseCategory::class,
                auditableId: $categoryId,
                oldValues: $snapshot,
            ));
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Partial-update a company expense category with diff-aware audit. Same pattern
 * as UpdateTaxAction. The `key` is NEVER mutated — it is stable because
 * pos_expenses.category stores the key string. A name change re-checks
 * (company_id, name) uniqueness excluding self. Audit event:
 * settings.expense_category.updated.
 */
final readonly class UpdateExpenseCategoryAction
{
    private const MUTABLE_FIELDS = ['name', 'name_ar', 'is_active', 'sort_order'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ExpenseCategory $cat, array $attributes, User $actor): ExpenseCategory
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $cat->company_id !== $companyId) {
            abort(404);
        }

        if (array_key_exists('name', $attributes)) {
            $newName = trim((string) $attributes['name']);
            if ($newName === '') {
                throw new RuntimeException('Expense category name is required.');
            }
            $attributes['name'] = $newName;
            if ($newName !== $cat->name) {
                $duplicate = ExpenseCategory::query()
                    ->where('company_id', $companyId)
                    ->where('name', $newName)
                    ->where('id', '!=', $cat->id)
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException('Another expense category with this name already exists.');
                }
            }
        }

        return DB::transaction(function () use ($cat, $attributes, $actor, $companyId): ExpenseCategory {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                // Normalise incoming values so a JSON "1"/5 doesn't read as a
                // change vs the stored bool / int.
                $newValue = match ($field) {
                    'is_active' => (bool) $attributes[$field],
                    'sort_order' => (int) $attributes[$field],
                    'name_ar' => $attributes[$field] === null ? null : (string) $attributes[$field],
                    default => (string) $attributes[$field],
                };
                if ($cat->{$field} == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $cat->{$field}, 'new' => $newValue];
                $cat->{$field} = $newValue;
            }

            if ($changes === []) {
                return $cat->fresh();
            }

            $cat->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.expense_category.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ExpenseCategory::class,
                auditableId: $cat->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $cat->fresh();
        });
    }
}

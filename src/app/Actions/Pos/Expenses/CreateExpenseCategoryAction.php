<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Create a company expense category for the actor's company.
 *
 * The stable `key` is derived from the name (slug, capped at 32 chars) and is
 * immutable thereafter — logged expenses store the key string. Pre-flight
 * (company_id, name) AND (company_id, key) duplicate checks so the error is
 * friendlier than the raw unique-constraint violation; the DB constraint still
 * backs us up under concurrent writes. Audit event:
 * settings.expense_category.created.
 */
final readonly class CreateExpenseCategoryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, is_active?: bool, sort_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor): ExpenseCategory
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Expense category name is required.');
        }

        $key = substr(Str::slug($name), 0, 32);

        $duplicate = ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($name, $key): void {
                $query->where('name', $name)->orWhere('key', $key);
            })
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('An expense category with this name already exists.');
        }

        return DB::transaction(function () use ($attributes, $name, $key, $actor, $companyId): ExpenseCategory {
            /** @var ExpenseCategory $category */
            $category = ExpenseCategory::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'name_ar' => $attributes['name_ar'] ?? null,
                'key' => $key,
                'is_active' => $attributes['is_active'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? 0,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.expense_category.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ExpenseCategory::class,
                auditableId: $category->id,
                newValues: [
                    'name' => $name,
                    'key' => $key,
                    'is_active' => (bool) ($attributes['is_active'] ?? true),
                ],
            ));

            return $category->fresh();
        });
    }
}

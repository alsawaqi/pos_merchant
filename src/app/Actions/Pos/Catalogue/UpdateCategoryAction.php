<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Partial-update a category. Fields: name / name_ar /
 * description / image_url / display_order / status. Diff-aware
 * audit event: catalogue.category.updated.
 */
final readonly class UpdateCategoryAction
{
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'description',
        'image_url',
        // Re-parent / un-nest (null = promote to top-level). Validity
        // (company-scoped, 2-level cap, no-self, no-has-children) is enforced
        // by UpdateCategoryRequest before we get here.
        'parent_id',
        'display_order',
        // Phase D2 — §5.5.1 branch availability (NULL = all branches).
        // The request speaks `branch_ids`; handle() translates.
        'branch_availability_json',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ProductCategory $category, array $attributes, User $actor): ProductCategory
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $category->company_id !== $companyId) {
            abort(404);
        }

        // Phase D2 — the request speaks `branch_ids`; the column is
        // branch_availability_json ([] / null both mean "all branches",
        // stored as NULL). Ownership is enforced by UpdateCategoryRequest.
        if (array_key_exists('branch_ids', $attributes)) {
            $attributes['branch_availability_json'] = CreateCategoryAction::normalizeBranchIds(
                is_array($attributes['branch_ids']) ? $attributes['branch_ids'] : null,
            );
        }

        return DB::transaction(function () use ($category, $attributes, $actor, $companyId): ProductCategory {
            $changes = [];

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $category->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;
                if ($oldComparable == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $category->{$field} = $newValue;
            }

            if ($changes === []) {
                return $category->fresh();
            }

            $category->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.category.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductCategory::class,
                auditableId: $category->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $category->fresh();
        });
    }
}

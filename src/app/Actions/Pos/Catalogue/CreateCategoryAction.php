<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\CategoryStatus;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Create a category for the actor's company.
 *
 * Validator on the controller side handles (company_id,
 * name) uniqueness; this action just does the atomic write
 * + audit.
 *
 * Audit event: catalogue.category.created.
 */
final readonly class CreateCategoryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, parent_id?: int|null, name_ar?: string|null, description?: string|null, image_url?: string|null, display_order?: int, branch_ids?: array<int|string>|null}  $attributes
     */
    public function handle(array $attributes, User $actor): ProductCategory
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($attributes, $actor, $companyId): ProductCategory {
            /** @var ProductCategory $category */
            $category = ProductCategory::query()->create([
                'company_id' => $companyId,
                // parent_id validity (company-scoped + 2-level cap) is enforced
                // by CreateCategoryRequest before we get here.
                'parent_id' => $attributes['parent_id'] ?? null,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'description' => $attributes['description'] ?? null,
                'image_url' => $attributes['image_url'] ?? null,
                'display_order' => $attributes['display_order'] ?? 0,
                // Phase D2 — §5.5.1 branch availability: NULL = all branches,
                // else the selected pos_branches ids (ownership enforced by
                // CreateCategoryRequest before we get here).
                'branch_availability_json' => self::normalizeBranchIds($attributes['branch_ids'] ?? null),
                'status' => CategoryStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.category.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductCategory::class,
                auditableId: $category->id,
                newValues: [
                    'name' => $category->name,
                    'name_ar' => $category->name_ar,
                    'parent_id' => $category->parent_id,
                    'display_order' => $category->display_order,
                    'branch_ids' => $category->branch_availability_json,
                ],
            ));

            return $category;
        });
    }

    /**
     * Phase D2 — empty / missing selection means "all branches", stored
     * as NULL; anything else becomes a deduped list of int branch ids.
     *
     * @param  array<int|string>|null  $branchIds
     * @return list<int>|null
     */
    public static function normalizeBranchIds(?array $branchIds): ?array
    {
        if ($branchIds === null || $branchIds === []) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $branchIds,
        )));
    }
}

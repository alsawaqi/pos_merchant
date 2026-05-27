<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soft-delete a category.
 *
 * Refuses if the category has ANY non-trashed products —
 * the migration's ON DELETE SET NULL would orphan them
 * silently, leaving menu items uncategorized. Better to
 * force the merchant to either move products or delete them
 * first.
 *
 * Audit event: catalogue.category.deleted.
 */
final readonly class DeleteCategoryAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(ProductCategory $category, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $category->company_id !== $companyId) {
            abort(404);
        }

        $productCount = $category->products()->count();
        if ($productCount > 0) {
            throw new RuntimeException(
                sprintf(
                    'This category has %d product(s). Re-assign or delete them first.',
                    $productCount,
                ),
            );
        }

        DB::transaction(function () use ($category, $actor, $companyId): void {
            $snapshot = [
                'name' => $category->name,
                'name_ar' => $category->name_ar,
            ];
            $categoryId = $category->id;

            $category->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.category.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductCategory::class,
                auditableId: $categoryId,
                oldValues: $snapshot,
            ));
        });
    }
}

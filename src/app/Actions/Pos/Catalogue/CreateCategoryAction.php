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
     * @param  array{name: string, name_ar?: string|null, description?: string|null, image_url?: string|null, display_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor): ProductCategory
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($attributes, $actor, $companyId): ProductCategory {
            /** @var ProductCategory $category */
            $category = ProductCategory::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'description' => $attributes['description'] ?? null,
                'image_url' => $attributes['image_url'] ?? null,
                'display_order' => $attributes['display_order'] ?? 0,
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
                    'display_order' => $category->display_order,
                ],
            ));

            return $category;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — create an add-on group for the actor's company.
 *
 * Validator on the controller enforces (company_id, name)
 * uniqueness; this Action just does the atomic write + audit.
 *
 * Audit event: catalogue.addon_group.created.
 */
final readonly class CreateAddOnGroupAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, selection_mode?: string, is_global?: bool, display_order?: int}  $attributes
     */
    /**
     * @param  array{name: string, name_ar?: string|null, selection_mode?: string, is_global?: bool, display_order?: int, owner_product_id?: int|null}  $attributes
     */
    public function handle(array $attributes, User $actor): AddOnGroup
    {
        $companyId = $this->tenant->requiredId();

        // v2 #6: a product-owned (product-unique) group is never global and is
        // auto-attached to its owner product, so it ships in that product's
        // /device/config add-on group ids — no device/api change needed.
        $ownerProductId = $attributes['owner_product_id'] ?? null;

        return DB::transaction(function () use ($attributes, $actor, $companyId, $ownerProductId): AddOnGroup {
            /** @var AddOnGroup $group */
            $group = AddOnGroup::query()->create([
                'company_id' => $companyId,
                'owner_product_id' => $ownerProductId,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'selection_mode' => $attributes['selection_mode'] ?? AddOnSelectionMode::Single->value,
                // Phase B — selection constraints (NULL = unbounded).
                'min_selections' => $attributes['min_selections'] ?? null,
                'max_selections' => $attributes['max_selections'] ?? null,
                'is_global' => $ownerProductId !== null ? false : ($attributes['is_global'] ?? false),
                'display_order' => $attributes['display_order'] ?? 0,
                'status' => 'active',
            ]);

            if ($ownerProductId !== null) {
                $group->products()->attach($ownerProductId);
                // Delta visibility: the owner product's addon_group_ids ride
                // the product row in the device config — bump it so delta
                // devices pick up the new private group (the wizard's fresh
                // products are new anyway; the standalone v2 #6 path is not).
                Product::query()->whereKey($ownerProductId)->update(['updated_at' => now()]);
            }

            // Phase B — category-level bindings (validated tenant-owned
            // by the request layer).
            if (! empty($attributes['category_ids'])) {
                $categoryIds = collect($attributes['category_ids'])->map(fn ($id): int => (int) $id)->all();
                $group->categories()->sync($categoryIds);
                // Delta visibility: category bindings ride the CATEGORY rows
                // in the device config — bump them so delta devices refresh.
                ProductCategory::query()->whereIn('id', $categoryIds)->update(['updated_at' => now()]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon_group.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOnGroup::class,
                auditableId: $group->id,
                newValues: [
                    'name' => $group->name,
                    'selection_mode' => $group->selection_mode->value,
                    'is_global' => $group->is_global,
                ],
            ));

            return $group;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 4.9 — add an option inside an add-on group.
 *
 * Cross-tenant defence in depth: even though the controller
 * looks up the group via tenant-scoped query, the Action
 * re-verifies the group's company_id matches the actor's.
 *
 * Audit event: catalogue.addon.created (includes price_delta
 * so audit shows pricing decisions, not just the name).
 */
final readonly class CreateAddOnAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private SyncAddOnConsumptionAction $syncConsumption,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, price_delta?: numeric-string|float|int, display_order?: int}  $attributes
     */
    public function handle(AddOnGroup $group, array $attributes, User $actor): AddOn
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $group->company_id !== $companyId) {
            throw new RuntimeException('The add-on group does not belong to your company.');
        }

        // P-G3 — resolve the linked product (the add-on IS that product).
        $linkedProductId = $this->resolveLinkedProduct(
            $attributes['linked_product_uuid'] ?? null,
            $companyId,
        );

        return DB::transaction(function () use ($group, $attributes, $actor, $companyId, $linkedProductId): AddOn {
            /** @var AddOn $addon */
            $addon = AddOn::query()->create([
                'company_id' => $companyId,
                'add_on_group_id' => $group->id,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'price_delta' => $attributes['price_delta'] ?? 0,
                // Phase B — pre-selected default in the customize sheet.
                'is_default' => (bool) ($attributes['is_default'] ?? false),
                // P-G3 — product-as-add-on (consumes the product's stock).
                'linked_product_id' => $linkedProductId,
                'display_order' => $attributes['display_order'] ?? 0,
                'status' => 'active',
            ]);

            // A single-select group can only have ONE default — making
            // this option the default clears any sibling's flag.
            if ($addon->is_default && $group->selection_mode?->value === 'single') {
                AddOn::query()
                    ->where('add_on_group_id', $group->id)
                    ->where('id', '!=', $addon->id)
                    ->update(['is_default' => false]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOn::class,
                auditableId: $addon->id,
                newValues: [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'name' => $addon->name,
                    'price_delta' => (string) $addon->price_delta,
                ],
            ));

            // PD3b — the option's stock-usage lines ride along at create
            // (nested transaction = savepoint; a bad line rolls back the
            // whole option, never a half-created one).
            if (! empty($attributes['consumption']) && is_array($attributes['consumption'])) {
                $this->syncConsumption->handle($addon, $attributes['consumption'], $actor);
            }

            return $addon;
        });
    }

    /**
     * P-G3 — resolve a linked-product uuid to its id: must belong to the
     * company and not be an internal item (cups are never sold, not even
     * as an add-on). Null stays null (a classic label-only option).
     */
    private function resolveLinkedProduct(?string $uuid, int $companyId): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->first();
        if ($product === null) {
            throw new RuntimeException('The linked product does not belong to your company.');
        }
        if ($product->is_internal) {
            throw new RuntimeException('An internal item cannot be sold as an add-on.');
        }

        return (int) $product->id;
    }
}

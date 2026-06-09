<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOnGroup;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 4.9 — soft-delete an add-on group.
 *
 * Refuses if the group is currently attached to any products
 * (the merchant must detach first) — otherwise the delete
 * would silently break every product's add-on rendering on
 * the POS, with no easy recovery path.
 *
 * Cascading addons + pivot rows are wiped automatically via
 * FK on delete cascade — see migration.
 *
 * Audit event: catalogue.addon_group.deleted.
 */
final readonly class DeleteAddOnGroupAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(AddOnGroup $group, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $group->company_id !== $companyId) {
            abort(404);
        }

        // A product-owned group (v2 #6) is private to its single product and is
        // deleted from that product — skip the shared-group "detach first"
        // guard (its lone pivot row cascades cleanly on delete).
        if ($group->owner_product_id === null) {
            $productCount = $group->products()->count();
            if ($productCount > 0) {
                throw new RuntimeException(sprintf(
                    'This add-on group is attached to %d product(s). Detach it from those products first.',
                    $productCount,
                ));
            }
        }

        DB::transaction(function () use ($group, $actor, $companyId): void {
            $snapshot = [
                'name' => $group->name,
                'is_global' => $group->is_global,
                'selection_mode' => $group->selection_mode?->value,
                'addons_count' => $group->addOns()->count(),
            ];
            $groupId = $group->id;

            $group->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon_group.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOnGroup::class,
                auditableId: $groupId,
                oldValues: $snapshot,
            ));
        });
    }
}

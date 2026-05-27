<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 4.9 — sync the product-specific add-on groups for a
 * product (idempotent replace).
 *
 * "Sync" semantics — caller passes the desired complete list
 * of group uuids; we attach what's missing, detach what's no
 * longer wanted, leave matches alone. One transaction, one
 * audit row that captures the before / after sets — instead
 * of N attach + N detach rows.
 *
 * Global groups (is_global=true) are NEVER in the pivot — if
 * a caller includes a global group's uuid we silently skip it
 * (no error: it doesn't change behaviour because the resolver
 * already includes globals). This matches the principle of
 * least surprise — the UI shows global groups as "always
 * attached, can't detach".
 *
 * Cross-tenant defence: every group uuid in the payload must
 * belong to the actor's company. A bogus or foreign uuid
 * aborts the whole sync (RuntimeException → 422).
 *
 * Audit event: catalogue.product.addons_synced.
 */
final readonly class SyncProductAddOnGroupsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, string>  $groupUuids  desired complete list of group uuids
     * @return array<int, AddOnGroup>  fresh-loaded groups attached to the product, in display order
     */
    public function handle(Product $product, array $groupUuids, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // Resolve every uuid up front — if ANY is bogus / cross-
        // tenant we abort before touching the pivot.
        $groupUuids = array_values(array_unique($groupUuids));
        $resolved = AddOnGroup::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $groupUuids)
            ->get();

        if ($resolved->count() !== count($groupUuids)) {
            throw new RuntimeException('One or more add-on groups in the payload do not belong to your company.');
        }

        // Skip globals from the pivot — they apply automatically.
        $pivotable = $resolved->where('is_global', false);
        $desiredIds = $pivotable->pluck('id')->all();

        return DB::transaction(function () use ($product, $desiredIds, $actor, $companyId): array {
            $before = $product->addOnGroups()->pluck('pos_addon_groups.id')->all();
            $product->addOnGroups()->sync($desiredIds);
            $after = $product->addOnGroups()->pluck('pos_addon_groups.id')->all();

            // Only audit if the set actually changed.
            sort($before);
            sort($after);
            if ($before !== $after) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.product.addons_synced',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Product::class,
                    auditableId: $product->id,
                    oldValues: ['addon_group_ids' => $before],
                    newValues: ['addon_group_ids' => $after],
                ));
            }

            return $product->addOnGroups()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->all();
        });
    }
}

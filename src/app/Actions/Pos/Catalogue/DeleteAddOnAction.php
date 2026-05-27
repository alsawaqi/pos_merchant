<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — soft-delete a single add-on.
 *
 * Phase 7 orders snapshot the add-on by id; soft delete keeps
 * historical receipts resolvable via withTrashed() while the
 * merchant retires a discontinued option.
 *
 * Audit event: catalogue.addon.deleted with price_delta snapshot
 * (helps dispute investigations: "the customer said the oat-milk
 * upcharge was 0.250 but you deleted it at 0.500").
 */
final readonly class DeleteAddOnAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(AddOn $addon, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $addon->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($addon, $actor, $companyId): void {
            $snapshot = [
                'add_on_group_id' => $addon->add_on_group_id,
                'name' => $addon->name,
                'price_delta' => (string) $addon->price_delta,
            ];
            $addonId = $addon->id;

            $addon->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOn::class,
                auditableId: $addonId,
                oldValues: $snapshot,
            ));
        });
    }
}

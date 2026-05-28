<?php

declare(strict_types=1);

namespace App\Actions\Pos\DeliveryProviders;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeliveryProvider;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6c — soft-delete a delivery provider.
 *
 * Per-product price-override rows survive (the FK
 * cascadeOnDelete only fires on a HARD delete). This is by
 * design — Phase 7+ historical orders will need to resolve a
 * provider's prices even after the merchant retires it.
 *
 * The POS picker filters out soft-deleted providers
 * (DeliveryProvider::scopeActive + the global SoftDeletes
 * scope).
 *
 * No future-order guard yet (Phase 7+ orders don't exist).
 * When they do, this gains a check refusing to soft-delete
 * a provider with any non-terminal orders.
 */
final readonly class DeleteDeliveryProviderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(DeliveryProvider $provider, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $provider->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($provider, $actor, $companyId): void {
            $providerId = $provider->id;
            $snapshot = [
                'name' => $provider->name,
                'color' => $provider->color,
                'is_active' => (bool) $provider->is_active,
            ];

            $provider->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_provider.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: DeliveryProvider::class,
                auditableId: $providerId,
                oldValues: $snapshot,
            ));
        });
    }
}

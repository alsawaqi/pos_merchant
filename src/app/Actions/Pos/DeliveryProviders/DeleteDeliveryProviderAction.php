<?php

declare(strict_types=1);

namespace App\Actions\Pos\DeliveryProviders;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OrderStatus;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * P-G7 — the promised future-order guard is now real: a
 * provider with PENDING-VERIFICATION delivery orders cannot be
 * retired until those orders are confirmed (or voided) — the
 * Deliveries settlement page needs the provider row to group
 * and reconcile them. Settled history (paid/void) is fine: the
 * orders carry a name snapshot + a nullOnDelete FK.
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

        $pendingCount = Order::query()
            ->where('company_id', $companyId)
            ->where('delivery_provider_id', $provider->id)
            ->where('status', OrderStatus::PendingVerification->value)
            ->count();
        if ($pendingCount > 0) {
            throw new RuntimeException(
                'This provider still has '.$pendingCount.' delivery order(s) awaiting verification — confirm them on the Deliveries page first.',
            );
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

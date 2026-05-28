<?php

declare(strict_types=1);

namespace App\Actions\Pos\DeliveryProviders;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\ProductDeliveryPrice;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6c — remove a per-product, per-provider price override.
 *
 * Hard-delete (no soft delete on the override row — see
 * model class doc). After removal, the price-resolution
 * chain falls back to products.delivery_price → base_price.
 *
 * Idempotent: removing an override that doesn't exist is a
 * silent no-op. The merchant might double-click; we shouldn't
 * surface that as an error.
 */
final readonly class RemoveProductDeliveryPriceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        Product $product,
        DeliveryProvider $provider,
        User $actor,
    ): void {
        $companyId = $this->tenant->requiredId();

        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $provider->company_id !== $companyId) {
            throw new RuntimeException('Delivery provider does not belong to your company.');
        }

        DB::transaction(function () use ($product, $provider, $actor, $companyId): void {
            /** @var ProductDeliveryPrice|null $existing */
            $existing = ProductDeliveryPrice::query()
                ->where('product_id', $product->id)
                ->where('delivery_provider_id', $provider->id)
                ->first();

            if ($existing === null) {
                // No-op — nothing to remove.
                return;
            }

            $snapshot = [
                'product_id' => $product->id,
                'delivery_provider_id' => $provider->id,
                'price' => (string) $existing->price,
            ];
            $existingId = $existing->id;

            $existing->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_price.removed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductDeliveryPrice::class,
                auditableId: $existingId,
                oldValues: $snapshot,
            ));
        });
    }
}

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
 * Phase 6c — set a product's row for one delivery provider.
 *
 * LAUNCH-P4 B3 — the row carries two things:
 *   - listed: false hides the product on that provider (owner decision 8);
 *   - price:  NULL = the product's delivery price, else its base price.
 * A listed product with no price of its own needs no row, so that case
 * removes the row (the same result as RemoveProductDeliveryPriceAction).
 *
 * Cross-tenant invariants enforced:
 *   - product.company_id == actor's company
 *   - provider.company_id == actor's company
 *
 * A price, when given, must be > 0 (0 is never a "remove" proxy).
 *
 * Audit event: catalogue.delivery_price.set with old/new values (removal:
 * catalogue.delivery_price.removed). Idempotent: same state in, no audit and
 * no write.
 */
final readonly class SetProductDeliveryPriceAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(
        Product $product,
        DeliveryProvider $provider,
        string|float|int|null $price,
        User $actor,
        bool $listed = true,
    ): ?ProductDeliveryPrice {
        $companyId = $this->tenant->requiredId();

        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $provider->company_id !== $companyId) {
            throw new RuntimeException('Delivery provider does not belong to your company.');
        }

        $priceString = null;
        if ($price !== null && $price !== '') {
            $priceString = number_format((float) $price, 3, '.', '');
            if ((float) $priceString <= 0) {
                throw new RuntimeException('Price must be greater than zero.');
            }
        }

        return DB::transaction(function () use ($product, $provider, $priceString, $listed, $actor, $companyId): ?ProductDeliveryPrice {
            /** @var ProductDeliveryPrice|null $existing */
            $existing = ProductDeliveryPrice::query()
                ->where('product_id', $product->id)
                ->where('delivery_provider_id', $provider->id)
                ->first();

            $old = $existing === null ? null : [
                'product_id' => $product->id,
                'delivery_provider_id' => $provider->id,
                'price' => $existing->price !== null ? (string) $existing->price : null,
                'listed' => (bool) ($existing->listed ?? true),
            ];
            $new = [
                'product_id' => $product->id,
                'delivery_provider_id' => $provider->id,
                'price' => $priceString,
                'listed' => $listed,
            ];

            // Listed at the default price = no row needed.
            if ($listed && $priceString === null) {
                if ($existing === null) {
                    return null;
                }
                $existing->delete();
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.delivery_price.removed',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: ProductDeliveryPrice::class,
                    auditableId: $existing->id,
                    oldValues: $old,
                ));

                return null;
            }

            if ($existing !== null) {
                if ($old === $new) {
                    return $existing->fresh();
                }
                $existing->forceFill(['price' => $priceString, 'listed' => $listed])->save();
                $row = $existing;
            } else {
                /** @var ProductDeliveryPrice $row */
                $row = ProductDeliveryPrice::query()->create([
                    'product_id' => $product->id,
                    'delivery_provider_id' => $provider->id,
                    'company_id' => $companyId,
                    'price' => $priceString,
                    'listed' => $listed,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_price.set',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductDeliveryPrice::class,
                auditableId: $row->id,
                oldValues: $old,
                newValues: $new,
            ));

            return $row->fresh();
        });
    }
}

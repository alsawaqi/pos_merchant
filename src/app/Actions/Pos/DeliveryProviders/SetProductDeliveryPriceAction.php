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
 * Phase 6c — set (or update) a per-product, per-provider
 * price override.
 *
 * Upsert semantics: if a row already exists for (product,
 * provider) it's updated; otherwise a new row is created.
 *
 * Cross-tenant invariants enforced:
 *   - product.company_id == actor's company
 *   - provider.company_id == actor's company
 *   - therefore product.company_id == provider.company_id
 *     (no risk of pricing a foreign-tenant product via my
 *      provider, or vice-versa)
 *
 * Price must be > 0. Removing a price override is a separate
 * action (RemoveProductDeliveryPriceAction); we don't allow
 * "set to 0" as a removal proxy because 0 is a legitimate
 * price (free promo item) and conflating the two would surprise.
 *
 * Audit event: catalogue.delivery_price.set with old/new
 * values when an existing row is updated, or just new on first
 * create. Idempotent: same price in, no audit + no DB write.
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
        string|float|int $price,
        User $actor,
    ): ProductDeliveryPrice {
        $companyId = $this->tenant->requiredId();

        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $provider->company_id !== $companyId) {
            throw new RuntimeException('Delivery provider does not belong to your company.');
        }

        $priceString = number_format((float) $price, 3, '.', '');
        if ((float) $priceString <= 0) {
            throw new RuntimeException('Price must be greater than zero.');
        }

        return DB::transaction(function () use ($product, $provider, $priceString, $actor, $companyId): ProductDeliveryPrice {
            /** @var ProductDeliveryPrice|null $existing */
            $existing = ProductDeliveryPrice::query()
                ->where('product_id', $product->id)
                ->where('delivery_provider_id', $provider->id)
                ->first();

            if ($existing !== null) {
                $oldPrice = (string) $existing->price;
                if ($oldPrice === $priceString) {
                    // No-op — same shape, skip audit + DB write.
                    return $existing->fresh();
                }
                $existing->forceFill(['price' => $priceString])->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.delivery_price.set',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: ProductDeliveryPrice::class,
                    auditableId: $existing->id,
                    oldValues: [
                        'product_id' => $product->id,
                        'delivery_provider_id' => $provider->id,
                        'price' => $oldPrice,
                    ],
                    newValues: [
                        'product_id' => $product->id,
                        'delivery_provider_id' => $provider->id,
                        'price' => $priceString,
                    ],
                ));

                return $existing->fresh();
            }

            /** @var ProductDeliveryPrice $created */
            $created = ProductDeliveryPrice::query()->create([
                'product_id' => $product->id,
                'delivery_provider_id' => $provider->id,
                'company_id' => $companyId,
                'price' => $priceString,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.delivery_price.set',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ProductDeliveryPrice::class,
                auditableId: $created->id,
                newValues: [
                    'product_id' => $product->id,
                    'delivery_provider_id' => $provider->id,
                    'price' => $priceString,
                ],
            ));

            return $created->fresh();
        });
    }
}

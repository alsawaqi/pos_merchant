<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create a product. Validates that the optional category_id
 * belongs to the actor's company (the controller request
 * also enforces this; this is defence in depth for any
 * future internal caller).
 *
 * Audit event: catalogue.product.created. base_price is
 * included in the audit payload (vs PIN/QR/password which
 * we keep out) — pricing isn't a credential and order
 * dispute investigations need the historical price.
 */
final readonly class CreateProductAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();

        // Cross-tenant category check: if a category_id is
        // supplied, it MUST belong to the actor's company.
        if (! empty($attributes['category_id'])) {
            $categoryOwned = ProductCategory::query()
                ->where('id', $attributes['category_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $categoryOwned) {
                throw new RuntimeException(
                    'The selected category does not belong to your company.',
                );
            }
        }

        return DB::transaction(function () use ($attributes, $actor, $companyId): Product {
            /** @var Product $product */
            $product = Product::query()->create([
                'company_id' => $companyId,
                'category_id' => $attributes['category_id'] ?? null,
                'sku' => $attributes['sku'] ?? null,
                'barcode' => $attributes['barcode'] ?? null,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'description' => $attributes['description'] ?? null,
                'image_url' => $attributes['image_url'] ?? null,
                'base_price' => $attributes['base_price'],
                // Phase 4.9 — per-product delivery override.
                'delivery_price' => $attributes['delivery_price'] ?? null,
                // Phase 7 — stock mode (the form always submitted it; it was
                // silently dropped here until Phase D2 fixed the omission).
                'stock_mode' => $attributes['stock_mode'] ?? 'untracked',
                // Phase D2 — unit-mode LOW STOCK badge threshold.
                'low_stock_threshold' => $attributes['low_stock_threshold'] ?? null,
                // P-G1.5 — default shelf life in days (NULL = keeps
                // indefinitely; the day-end disposition flow skips it).
                'shelf_life_days' => $attributes['shelf_life_days'] ?? null,
                'cost_price' => $attributes['cost_price'] ?? null,
                'tax_rate' => $attributes['tax_rate'] ?? null,
                // Phase D2 — §5.5.3 flags. tax_inclusive is display-only for
                // now (totals still add tax on top); the tablet flag gates
                // the future customer tablet menu, not the staff POS.
                'tax_inclusive' => (bool) ($attributes['tax_inclusive'] ?? false),
                'show_on_customer_tablet' => (bool) ($attributes['show_on_customer_tablet'] ?? true),
                // P-G2 — internal item: never on the POS menu or tablet.
                'is_internal' => (bool) ($attributes['is_internal'] ?? false),
                // PD3a — physical-item kind (set by the physical-items
                // endpoint only; NULL for catalogue products).
                'internal_purpose' => $attributes['internal_purpose'] ?? null,
                // G1 — menu time-window ('HH:MM:SS' strings, both NULL
                // = always available, start > end wraps midnight).
                'available_from' => $attributes['available_from'] ?? null,
                'available_until' => $attributes['available_until'] ?? null,
                'display_order' => $attributes['display_order'] ?? 0,
                'status' => ProductStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                newValues: [
                    'name' => $product->name,
                    'category_id' => $product->category_id,
                    'sku' => $product->sku,
                    'base_price' => (string) $product->base_price,
                    'tax_rate' => $product->tax_rate !== null ? (string) $product->tax_rate : null,
                ],
            ));

            return $product;
        });
    }
}

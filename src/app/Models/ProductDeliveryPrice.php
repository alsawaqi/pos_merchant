<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductDeliveryPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6c — per-provider price override for a product.
 *
 * One row per (product, delivery_provider) — unique constraint
 * enforced at the DB level. Missing override → Product
 * ::resolvedDeliveryPriceFor() falls back to delivery_price
 * → base_price.
 *
 * company_id denormalised from the product so:
 *   1. Tenant-scoped reports skip the join.
 *   2. The Action layer cross-checks product.company ==
 *      provider.company == this row's company before write —
 *      catches misconfiguration loud.
 *
 * No soft delete. Removing an override is a clean operation
 * (just falls back to the next step in the chain).
 *
 * Schema owned by pos_admin's 2026_06_03_010100 migration.
 */
#[Fillable([
    'product_id',
    'delivery_provider_id',
    'company_id',
    'price',
])]
class ProductDeliveryPrice extends Model
{
    /** @use HasFactory<ProductDeliveryPriceFactory> */
    use HasFactory;

    protected $table = 'pos_product_delivery_prices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<DeliveryProvider, $this>
     */
    public function deliveryProvider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeliveryProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 6c — per-merchant 3rd-party delivery provider
 * (blueprint §6.3).
 *
 * The aggregators a merchant works with — Talabat, Otlob,
 * Hungerstation, Toyou, or in-house contracts. Each merchant
 * maintains their own list (per-company tenancy).
 *
 * Per-product price overrides live in pos_product_delivery_prices
 * — see prices() below. The Phase 8 POS sale pipeline resolves
 * a product's price for a given provider via Product
 * ::resolvedDeliveryPriceFor($providerId).
 *
 * Soft delete preserves historical order references (Phase 7+
 * orders will reference delivery_provider_id). The POS picker
 * filters by is_active + non-deleted.
 *
 * Schema owned by pos_admin's 2026_06_03_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'color',
    'commission_percent',
    'is_active',
    'sort_order',
])]
class DeliveryProvider extends Model
{
    /** @use HasFactory<DeliveryProviderFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_delivery_providers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            // P-G7 — the provider's cut of a delivery order. Snapshotted
            // onto each order at punch; this is only the CURRENT rate.
            'commission_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Per-product price overrides for this provider.
     *
     * @return HasMany<ProductDeliveryPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductDeliveryPrice::class, 'delivery_provider_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

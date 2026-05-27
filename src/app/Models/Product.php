<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An orderable item the merchant sells.
 *
 * Money handling: base_price + cost_price are decimal(12,3)
 * for OMR baisas precision. Cast to `decimal:3` on the model
 * so reads always return a string with 3 decimals (Laravel's
 * decimal cast keeps precision intact across PHP's float
 * peril). Frontend re-parses to BigInt-like math when
 * tallying line items.
 *
 * tax_rate: NULL means "use company default". A non-NULL
 * value overrides, including 0.00 (zero-rated). See the
 * Phase 6 tax model discussion in the migration comment.
 *
 * Schema owned by pos_admin
 * (2026_05_27_040100_create_pos_products_table).
 */
#[Fillable([
    'uuid',
    'company_id',
    'category_id',
    'sku',
    'barcode',
    'name',
    'name_ar',
    'description',
    'image_url',
    'base_price',
    'cost_price',
    'tax_rate',
    'display_order',
    'status',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_products';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:3',
            'cost_price' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'display_order' => 'integer',
            'status' => ProductStatus::class,
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
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }
}

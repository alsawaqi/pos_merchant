<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryStatus;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Menu category — Drinks, Mains, Desserts, etc.
 *
 * Two-level hierarchy: a NULL parent_id is a top-level category; a set
 * parent_id makes it a subcategory of that parent. Nesting is capped at two
 * levels (a subcategory can't itself be a parent) — enforced in the
 * Create/Update request + action layer.
 *
 * Schema owned by pos_admin (table created 2026_05_27_040000; parent_id added
 * 2026_06_12_010000). pos_merchant reads + writes via the narrow whitelist
 * below, always through {@see \App\Actions\Pos\Catalogue\*}.
 */
#[Fillable([
    'uuid',
    'company_id',
    'parent_id',
    'name',
    'name_ar',
    'description',
    'image_url',
    'display_order',
    'status',
])]
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_product_categories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'display_order' => 'integer',
            'parent_id' => 'integer',
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
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id')
            ->orderBy('display_order')
            ->orderBy('name');
    }

    /**
     * The parent category, or null for a top-level category.
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child categories nested one level under this top-level category.
     *
     * @return HasMany<ProductCategory, $this>
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('display_order')
            ->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CategoryStatus::Active->value);
    }
}

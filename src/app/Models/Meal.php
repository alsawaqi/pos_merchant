<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Catalogue\MealMains;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * LAUNCH combo add-on — a "Make it a meal?" setup (owner decision 4): the
 * meal price added to the main's own price, the categories whose products
 * are the mains (new products join automatically), the unticked mains and
 * the meal's lines. A main belongs to at most one ACTIVE meal (the clash
 * rule, {@see MealMains}).
 *
 * Schema owned by pos_admin (2026_10_07_100001): meal_price >= 0, status
 * active | inactive, until >= from (CHECKs).
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'name_ar',
    'meal_price',
    'status',
    'on_sale_from',
    'on_sale_until',
    'sort_order',
])]
class Meal extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'pos_meals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meal_price' => 'decimal:3',
            'on_sale_from' => 'date:Y-m-d',
            'on_sale_until' => 'date:Y-m-d',
            'sort_order' => 'integer',
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

    /** @return BelongsToMany<ProductCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'pos_meal_categories', 'meal_id', 'category_id')
            ->withPivot('company_id')->withTimestamps();
    }

    /** @return BelongsToMany<Product, $this> */
    public function excludedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'pos_meal_excluded_products', 'meal_id', 'product_id')
            ->withPivot('company_id')->withTimestamps();
    }

    /** @return HasMany<ComboLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ComboLine::class, 'meal_id')->orderBy('sort_order')->orderBy('id');
    }
}

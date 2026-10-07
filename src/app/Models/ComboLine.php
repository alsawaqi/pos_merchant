<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LAUNCH combo add-on — one line of a combo (combo_product_id) or of a meal
 * (meal_id): 'fixed' (product × quantity, always included, may offer
 * upgrades) or 'choice' ("pick pick_count from category_id", the question
 * name / name_ar, per-item overrides in pos_combo_line_items).
 *
 * Schema owned by pos_admin (2026_10_07_100001): exactly one owner, the kind
 * columns, quantity 1..99, pick 1..20 (Postgres CHECKs).
 */
#[Fillable([
    'company_id',
    'combo_product_id',
    'meal_id',
    'kind',
    'product_id',
    'quantity',
    'category_id',
    'pick_count',
    'name',
    'name_ar',
    'sort_order',
])]
class ComboLine extends Model
{
    use BelongsToCompany;

    public const FIXED = 'fixed';

    public const CHOICE = 'choice';

    protected $table = 'pos_combo_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'pick_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id')->withTrashed();
    }

    /** @return HasMany<ComboLineUpgrade, $this> */
    public function upgrades(): HasMany
    {
        return $this->hasMany(ComboLineUpgrade::class, 'line_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ComboLineItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ComboLineItem::class, 'line_id')->orderBy('id');
    }
}

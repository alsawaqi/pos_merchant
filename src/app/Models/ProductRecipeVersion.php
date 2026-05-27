<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductRecipeVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5b — pre-edit snapshot of a product's recipe.
 *
 * Append-only. Reading: ordered by edited_at desc. recipe_json
 * is a denormalised array of
 *   [{ingredient_id, ingredient_name, quantity, unit, unit_cost_at_time}, ...]
 * so the version is meaningful even if ingredients were later
 * soft-deleted.
 *
 * Why text+JSON cast instead of native json column: portability
 * across the sqlite test mirror. Tests still get full
 * decode/encode behaviour via the array cast.
 *
 * Schema owned by pos_admin's 2026_05_30_010100 migration.
 */
#[Fillable([
    'product_id',
    'recipe_json',
    'edited_by_user_id',
    'note',
    'edited_at',
])]
class ProductRecipeVersion extends Model
{
    /** @use HasFactory<ProductRecipeVersionFactory> */
    use HasFactory;

    protected $table = 'pos_product_recipe_versions';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipe_json' => 'array',
            'edited_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}

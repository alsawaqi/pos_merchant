<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use App\Enums\IngredientUnit;
use Database\Factories\ProductRecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5b — a single line in a product's recipe.
 *
 * (product, ingredient) is composite-unique — an ingredient
 * appears at most once per recipe. UpdateProductRecipeAction
 * is the only legitimate writer; merging duplicate lines
 * before insert is the action's responsibility.
 *
 * unit_at_set is denormalised from the ingredient's unit at
 * the moment this line was written. If the merchant later
 * changes the ingredient's unit (Phase 5a blocks this when
 * history exists, but the column is here for defensive
 * resilience), the recipe still reflects the intent at set
 * time.
 *
 * LAUNCH-P3 P3-1 — entered_unit / entered_quantity record how the line was
 * typed ("5 g", "2 @piece") so the editor reopens it unchanged; quantity +
 * unit_at_set keep meaning the BASE quantity and unit (the device API reads
 * them). NULL entered columns (pre-P3 lines) read as "entered in the base".
 * The ingredient may be a prep item (P3-4): pos_api explodes it into raw
 * ingredients when an order line's recipe is copied.
 *
 * Schema owned by pos_admin's 2026_05_30_010000 migration (entered columns:
 * 2026_10_02_100003).
 */
#[Fillable([
    'product_id',
    'ingredient_id',
    'quantity',
    'unit_at_set',
    'sort_order',
    'entered_unit',
    'entered_quantity',
])]
class ProductRecipe extends Model
{
    /** @use HasFactory<ProductRecipeFactory> */
    use HasFactory;

    protected $table = 'pos_product_recipes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => ScaledDecimal::class.':3,4',
            'unit_at_set' => IngredientUnit::class,
            'sort_order' => 'integer',
            'entered_quantity' => ScaledDecimal::class.':0,4',
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
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PD3b — one stock-usage line on an add-on option.
 *
 * Either an INGREDIENT line (ingredient_id + quantity in the
 * ingredient's BASE unit + unit = that base unit) or a PRODUCT line
 * (component_product_id: packaging physical item, prepared cooked
 * product, or bought-in unit product; quantity in whole pieces) —
 * exactly one of the two refs is set (app-enforced XOR).
 *
 * direction 'add' consumes on top of the parent product's recipe and
 * components when the option is picked; 'remove' subtracts from them.
 * The pay-time engine in pos_api merges per ingredient/product and
 * clamps the effective consumption at zero — a removal never restocks.
 * Quantities are per ONE parent line unit.
 */
#[Fillable([
    'add_on_id',
    'ingredient_id',
    'component_product_id',
    'direction',
    'quantity',
    'unit',
    'display_order',
])]
class AddOnConsumption extends Model
{
    public const DIRECTION_ADD = 'add';

    public const DIRECTION_REMOVE = 'remove';

    protected $table = 'pos_addon_consumptions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AddOn, $this>
     */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}

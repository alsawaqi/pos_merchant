<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LAUNCH packaging add-on (owner decision 3) — one line of the merchant's
 * per-order packaging: for one order type (dine_in / quick / to_go /
 * delivery), an ingredient (in its BASE unit) XOR a physical item (in
 * pieces), taken ONCE per whole order when pos_api takes the order's stock.
 * One list per merchant, the same for every branch.
 *
 * Schema owned by pos_admin (2026_10_06_1100xx).
 */
#[Fillable([
    'company_id',
    'order_type',
    'ingredient_id',
    'product_id',
    'quantity',
    'unit',
    'entered_unit',
    'entered_quantity',
    'sort_order',
])]
class OrderPackagingLine extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'pos_order_packaging_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => ScaledDecimal::class.':3,4',
            'entered_quantity' => ScaledDecimal::class.':0,4',
            'sort_order' => 'integer',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}

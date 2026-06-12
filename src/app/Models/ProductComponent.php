<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G2 — one physical-item line of a product (pos_product_components):
 * per ONE unit sold of `product_id`, consume `quantity` of the
 * unit-mode `component_product_id` from the branch's unit stock
 * (coffee = 1 x cup 12oz + 1 x lid).
 *
 * Consumption happens in pos_api at order.pay (and reverses on void) —
 * this app manages the definitions. ONE level only: components have no
 * components. Schema owned by pos_admin (2026_07_16_010000).
 */
#[Fillable([
    'product_id',
    'component_product_id',
    'quantity',
])]
class ProductComponent extends Model
{
    protected $table = 'pos_product_components';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
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
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}

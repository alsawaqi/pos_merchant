<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — central company stock balance for a UNIT (finished-good) product.
 * One row per (company, product); `quantity` is the central pool held before
 * distributing units to branches. Schema owned by pos_admin
 * (2026_06_25_010100_create_pos_product_stock_table).
 */
#[Fillable([
    'company_id',
    'product_id',
    'quantity',
    'last_movement_at',
])]
class ProductStock extends Model
{
    protected $table = 'pos_product_stock';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

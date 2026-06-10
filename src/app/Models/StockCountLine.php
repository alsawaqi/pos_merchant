<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase A (Additions §2.8) — one ingredient's row inside a day-end
 * stock count.
 *
 * expected_units freezes the running balance at count time and
 * variance_units = counted − expected, so the line stays a faithful
 * historical record after later movements shift the live balance.
 * stock_movement_id points at the variance movement this line
 * produced (waste on shortfall / adjustment on overage); NULL when
 * the count matched exactly.
 *
 * No timestamps — lines are immutable children of the header.
 */
#[Fillable([
    'stock_count_id',
    'ingredient_id',
    'counted_pieces',
    'counted_units',
    'expected_units',
    'variance_units',
    'unit_cost_at_time',
    'stock_movement_id',
])]
class StockCountLine extends Model
{
    protected $table = 'pos_stock_count_lines';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_pieces' => 'decimal:3',
            'counted_units' => 'decimal:3',
            'expected_units' => 'decimal:3',
            'variance_units' => 'decimal:3',
            'unit_cost_at_time' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}

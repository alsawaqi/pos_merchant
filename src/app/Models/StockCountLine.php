<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase A (Additions §2.8) — one ingredient's row inside a day-end
 * stock count.
 *
 * expected_units freezes the book balance AT THE COUNT MOMENT and
 * variance_units = counted − expected, so the line stays a faithful
 * historical record after later movements shift the live balance.
 * stock_movement_id points at the variance movement this line
 * produced (waste on shortfall / adjustment on overage); NULL when
 * the count matched exactly.
 *
 * LAUNCH-P2 P2-6 — a movement dated before the count that reaches the
 * books after it (an offline sale synced late) is folded in: expected /
 * variance are recomputed, late_movement_units accumulates the fold and
 * waste_record_id points at the line's reconciliation waste, which
 * follows the fair shortfall. Lines are otherwise immutable.
 *
 * No timestamps — lines are children of the header.
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
    'late_movement_units',
    'waste_record_id',
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
            'counted_pieces' => ScaledDecimal::class.':3,4',
            'counted_units' => ScaledDecimal::class.':3,4',
            'expected_units' => ScaledDecimal::class.':3,4',
            'variance_units' => ScaledDecimal::class.':3,4',
            'unit_cost_at_time' => ScaledDecimal::class.':3,6',
            'late_movement_units' => ScaledDecimal::class.':3,4',
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

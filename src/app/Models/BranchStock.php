<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BranchStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5a — per-(branch, ingredient) current stock balance.
 *
 * Invariant: balance.quantity == SUM(movements.quantity) for
 * the same (branch_id, ingredient_id). Maintained atomically
 * inside WriteStockMovementAction's DB transaction.
 *
 * No soft delete — when stock hits zero we keep the row so the
 * next restock doesn't have to recreate it. The movement
 * ledger carries the history regardless.
 *
 * Schema owned by pos_admin's 2026_05_29_010200 migration.
 */
#[Fillable([
    'branch_id',
    'ingredient_id',
    'quantity',
    'last_movement_at',
])]
class BranchStock extends Model
{
    /** @use HasFactory<BranchStockFactory> */
    use HasFactory;

    protected $table = 'pos_branch_stock';

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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Healthy / Low / Critical based on the ingredient's
     * min_stock_threshold. NULL threshold → always Healthy.
     * Used by the Branch Stock UI for the badge column and
     * the dashboard's "inventory alerts" tile in Phase 7.
     */
    public function healthLevel(): string
    {
        $threshold = $this->ingredient?->min_stock_threshold;
        if ($threshold === null) {
            return 'healthy';
        }
        $qty = (float) $this->quantity;
        $threshold = (float) $threshold;
        if ($qty <= 0) {
            return 'critical';
        }
        if ($qty < $threshold) {
            return 'low';
        }
        return 'healthy';
    }
}

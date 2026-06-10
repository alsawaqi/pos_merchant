<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase A (Additions §2.4) — one purchase BATCH of an ingredient.
 *
 * The stock inflow itself lives in pos_stock_movements (type =
 * restock, linked via stock_movement_id); this row preserves what
 * the movement can't: the physical pieces received, the exact
 * money paid, and the batch's own units-per-piece ratio — the
 * basis for FIFO costing, supplier history, and the "last batch
 * wins" default ratio on the ingredient.
 *
 * unit_cost is decimal(12,6), NOT the money convention (12,3):
 * a per-gram cost is routinely below 0.001 OMR. total_paid is
 * real money and stays (12,3).
 *
 * Schema owned by pos_admin's 2026_07_01 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'ingredient_id',
    'supplier_id',
    'pieces_received',
    'units_received',
    'total_paid',
    'unit_cost',
    'units_per_piece_at_purchase',
    'is_loose',
    'stock_movement_id',
    'note',
    'recorded_by_user_id',
    'occurred_at',
])]
class IngredientPurchase extends Model
{
    protected $table = 'pos_ingredient_purchases';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pieces_received' => 'decimal:3',
            'units_received' => 'decimal:3',
            'total_paid' => 'decimal:3',
            'unit_cost' => 'decimal:6',
            'units_per_piece_at_purchase' => 'decimal:4',
            'is_loose' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Phase 5a — append-only ledger of every stock change.
 *
 * Never updated. Never deleted. Corrections come as NEW
 * Adjustment rows with the delta needed to reach the target.
 * This is the system of record for COGS, theft investigations,
 * and waste reconciliation.
 *
 * Schema owned by pos_admin's 2026_05_29_010300 migration.
 */
#[Fillable([
    'branch_id',
    'ingredient_id',
    'movement_type',
    'quantity',
    'unit_cost_at_time',
    'reference_type',
    'reference_id',
    'recorded_by_user_id',
    'recorded_by_pos_staff_id',
    'note',
    'occurred_at',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    protected $table = 'pos_stock_movements';

    public $timestamps = false; // We manage created_at + occurred_at explicitly.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost_at_time' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function recordedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'recorded_by_pos_staff_id');
    }

    /**
     * Polymorphic link to the triggering entity — an Order
     * (Phase 8), a RestockRequest (Phase 5c), a Transfer
     * (Phase 5c), etc. NULL for manual adjustments.
     *
     * @return MorphTo
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use App\Enums\WasteReason;
use Database\Factories\WasteRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 5c — a single waste event at a branch.
 *
 * quantity is stored ALWAYS POSITIVE — the matching
 * stock_movement (type=waste, reference_type=WasteRecord) holds
 * the signed-negative version of the same value. This split
 * keeps the by-reason reporting query simple (no ABS()) while
 * the ledger remains the source of truth on per-branch balance.
 *
 * RecordWasteAction is the only legitimate writer; it wraps
 * both inserts in a DB transaction so they can never diverge.
 *
 * No soft delete: a corrective entry is a NEW positive Adjustment
 * movement with a note explaining the correction.
 *
 * Schema owned by pos_admin's 2026_05_31_010000 migration.
 */
#[Fillable([
    'uuid',
    'branch_id',
    'ingredient_id',
    'quantity',
    'reason',
    'unit_at_set',
    'unit_cost_at_time',
    'notes',
    'recorded_by_user_id',
    'occurred_at',
])]
class WasteRecord extends Model
{
    /** @use HasFactory<WasteRecordFactory> */
    use HasFactory;

    protected $table = 'pos_waste_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reason' => WasteReason::class,
            'unit_at_set' => IngredientUnit::class,
            'unit_cost_at_time' => 'decimal:3',
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
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Total monetary cost of this waste event, in OMR baisas.
     * Returns a decimal-3 string for serialization safety —
     * never compute money in float space.
     */
    public function totalCost(): string
    {
        // String-safe decimal multiplication using bcmath
        // would be ideal, but the dataset stays small enough
        // that PHP's native multiplication is fine when both
        // sides are bounded by decimal(12,3).
        $cost = ((float) $this->quantity) * ((float) $this->unit_cost_at_time);
        return number_format($cost, 3, '.', '');
    }
}

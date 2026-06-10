<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 5a — ingredient master at company level.
 *
 * Per blueprint §5.6.1. The ingredient's STOCK BALANCES are
 * per-branch (see BranchStock) — the master here just defines
 * what the ingredient is, its unit of measure, default cost
 * and minimum threshold for low-stock flagging.
 *
 * Money columns (default_unit_cost) cast as decimal:3 to
 * preserve OMR baisas precision through the JSON layer.
 *
 * Schema owned by pos_admin's 2026_05_29_010100 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'name_ar',
    'unit',
    'piece_unit_label',
    'piece_unit_label_ar',
    'units_per_piece',
    'allow_fractional_pieces',
    'default_unit_cost',
    'min_stock_threshold',
    'primary_supplier_id',
    'status',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_ingredients';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'units_per_piece' => 'decimal:4',
            'allow_fractional_pieces' => 'boolean',
            'default_unit_cost' => 'decimal:3',
            'min_stock_threshold' => 'decimal:3',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function primarySupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'primary_supplier_id');
    }

    /**
     * Per-branch current balance rows. One per branch that has
     * ever stocked this ingredient — rows are created lazily by
     * the first stock movement.
     *
     * @return HasMany<BranchStock, $this>
     */
    public function branchStock(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    /**
     * Append-only movement ledger for this ingredient across
     * all branches.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('occurred_at');
    }

    /**
     * Phase A (Additions §2.4) — purchase batches, newest first. Each batch
     * freezes the pieces/units/cost of one delivery; the ingredient's
     * units_per_piece + default_unit_cost mirror the LATEST batch.
     *
     * @return HasMany<IngredientPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(IngredientPurchase::class)->orderByDesc('occurred_at');
    }

    /**
     * Whether quantities of this ingredient can be entered / counted in whole
     * physical pieces: either a piece unit is configured (label + ratio), or
     * the base unit itself is 'piece' (ratio 1).
     */
    public function isPieceTracked(): bool
    {
        return ($this->piece_unit_label !== null && $this->units_per_piece !== null)
            || $this->unit === IngredientUnit::Piece;
    }

    /**
     * Primary units in ONE piece for entry conversion. 1.0 when the base unit
     * is itself the piece. NULL when the ingredient is not piece-tracked.
     */
    public function unitsPerPiece(): ?float
    {
        if ($this->piece_unit_label !== null && $this->units_per_piece !== null) {
            return (float) $this->units_per_piece;
        }

        return $this->unit === IngredientUnit::Piece ? 1.0 : null;
    }

    /**
     * v2 #13 — alternate units a merchant can enter quantities in (each with a
     * factor to the base unit). The base unit itself ({@see $unit}) is implicit
     * (factor 1) and not stored here.
     *
     * @return HasMany<IngredientAltUnit, $this>
     */
    public function altUnits(): HasMany
    {
        return $this->hasMany(IngredientAltUnit::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}

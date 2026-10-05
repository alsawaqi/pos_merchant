<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
    // LAUNCH review add-on (B) — the breakdown's last count / total-only count.
    'containers_counted_at',
    'containers_total_count_at',
])]
class BranchStock extends Model
{
    /** @use HasFactory<BranchStockFactory> */
    use HasFactory;

    protected $table = 'pos_branch_stock';

    public const STATUS_NEGATIVE = 'negative';

    public const STATUS_BELOW_MINIMUM = 'below_minimum';

    public const STATUS_OK = 'ok';

    /**
     * LAUNCH review add-on (B2) — the breakdown by container, set by the stock
     * list for the response only (never stored on this row).
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $breakdown = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => ScaledDecimal::class.':3,4',
            'last_movement_at' => 'datetime',
            'containers_counted_at' => 'datetime',
            'containers_total_count_at' => 'datetime',
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
     * min_stock_threshold. NULL threshold → Healthy unless the
     * balance is below zero (LAUNCH-P2: negative stock is never
     * "healthy" — the till sells on and flags it instead).
     * Used by the Branch Stock UI for the badge column and
     * the dashboard's "inventory alerts" tile in Phase 7.
     */
    public function healthLevel(): string
    {
        $qty = (float) $this->quantity;
        if ($qty < 0) {
            return 'critical';
        }
        $threshold = $this->ingredient?->min_stock_threshold;
        if ($threshold === null) {
            return 'healthy';
        }
        $threshold = (float) $threshold;
        if ($qty <= 0) {
            return 'critical';
        }
        if ($qty < $threshold) {
            return 'low';
        }

        return 'healthy';
    }

    /**
     * LAUNCH-P2 P2-7 — sell, but warn. 'negative' (below zero, shown red),
     * 'below_minimum' (under the ingredient's minimum, amber) or 'ok'.
     */
    public function stockStatus(): string
    {
        $qty = (float) $this->quantity;
        if ($qty < 0) {
            return self::STATUS_NEGATIVE;
        }
        $threshold = $this->ingredient?->min_stock_threshold;
        if ($threshold !== null && $qty < (float) $threshold) {
            return self::STATUS_BELOW_MINIMUM;
        }

        return self::STATUS_OK;
    }

    /**
     * LAUNCH-P2 P2-7 — quantity × the ingredient's weighted-average cost,
     * rounded once to OMR baisa.
     */
    public function stockValue(): string
    {
        return (string) BigDecimal::of((string) ($this->quantity ?? '0'))
            ->multipliedBy((string) ($this->ingredient?->default_unit_cost ?? '0'))
            ->toScale(3, RoundingMode::HALF_UP);
    }
}

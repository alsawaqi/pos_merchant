<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P-G1 — a kitchen production batch (pos_productions). A "cooked" product
 * made ahead of sale by the chef: ingredients are consumed when the batch
 * STARTS (they have physically left the shelf), and the finished pieces
 * land in pos_branch_product.stock_qty when it FINISHES.
 *
 * Rows are written EXCLUSIVELY by pos_api (the device Kitchen screen,
 * online-only); this app only reads them for the Production history page.
 * Schema owned by pos_admin (2026_07_14_010000_create_pos_productions_tables).
 */
#[Fillable([])]
class Production extends Model
{
    protected $table = 'pos_productions';

    protected $guarded = ['*'];

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ProductionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class);
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function startedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'started_by_staff_id');
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function finishedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'finished_by_staff_id');
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function cancelledByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'cancelled_by_staff_id');
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function cancelApprovedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'cancel_approved_by_staff_id');
    }
}

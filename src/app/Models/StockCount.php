<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Phase A (Additions §2.8) — a day-end physical stock count at
 * one branch. Header row; the per-ingredient counted/expected/
 * variance figures live on StockCountLine.
 *
 * Submitted in one shot (no draft state): the variance movements
 * are written in the same transaction, so a count either fully
 * happened or didn't. Counts come from a portal user (merchant
 * web) or POS staff (device sync) — exactly one recorded_by
 * column is set.
 *
 * Schema owned by pos_admin's 2026_07_01 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'note',
    'recorded_by_user_id',
    'recorded_by_pos_staff_id',
    'counted_at',
])]
class StockCount extends Model
{
    protected $table = 'pos_stock_counts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
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
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
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
    public function recordedByPosStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'recorded_by_pos_staff_id');
    }
}

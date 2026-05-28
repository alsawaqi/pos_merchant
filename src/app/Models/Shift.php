<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShiftStatus;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 7a — POS cashier shift (blueprint §10.8).
 *
 * One row per cashier-shift on a device. Opening capture
 * recorded at opened_at + opening_cash; closing capture
 * recorded at closed_at + closing_cash + expected_cash +
 * variance.
 *
 * variance = closing_cash - expected_cash. Negative variance
 * means the cashier is SHORT — the audit-trigger case the
 * merchant follows up on.
 *
 * Schema owned by pos_admin's 2026_06_04_020100 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'device_id',
    'staff_id',
    'opened_at',
    'closed_at',
    'opening_cash',
    'closing_cash',
    'expected_cash',
    'variance',
    'status',
    'note',
])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $table = 'pos_shifts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:3',
            'closing_cash' => 'decimal:3',
            'expected_cash' => 'decimal:3',
            'variance' => 'decimal:3',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'staff_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::Open->value);
    }
}

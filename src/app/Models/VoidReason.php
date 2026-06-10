<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase B (Additions §1.2) — a company void reason code.
 *
 * Every order void at the POS picks one of these. The two flags drive
 * behaviour, not just reporting:
 *   affects_inventory — TRUE = the food was actually made (Quality
 *     Issue): voiding the order KEEPS the recipe ingredients consumed
 *     and the loss surfaces in the Loss/Waste voids breakdown.
 *     FALSE = never prepared (Wrong Order Entry): the void restores
 *     inventory. (The doc's affects_cogs flag is folded in — COGS
 *     derives from recipe snapshots here, so consumed == costed.)
 *   requires_manager — advisory per-code flag layered on top of the
 *     company-wide order_cancel_positions device policy.
 *
 * `code` is a stable slug snapshotted onto voided orders (the master
 * row can be renamed/soft-deleted without breaking history). Schema
 * owned by pos_admin's 2026_07_02 migration; same lifecycle as
 * {@see ExpenseCategory}.
 */
#[Fillable([
    'uuid',
    'company_id',
    'code',
    'name',
    'name_ar',
    'affects_inventory',
    'requires_manager',
    'is_active',
    'sort_order',
])]
class VoidReason extends Model
{
    use SoftDeletes;

    protected $table = 'pos_void_reasons';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affects_inventory' => 'boolean',
            'requires_manager' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

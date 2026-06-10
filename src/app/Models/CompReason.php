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
 * Phase B (Additions §1.2) — a company comp reason (Long Wait, Staff
 * Meal, …). A comp is a manager-approved write-off of a line or a
 * whole order: inventory deducts AS IF SOLD (the food went to the
 * customer) and the value is reported separately from discounts and
 * voids.
 *
 * max_amount caps a SINGLE comp under this reason (OMR; NULL = no
 * cap). Manager approval is ALWAYS required for comps per the doc —
 * hence no requires_manager column. Schema owned by pos_admin's
 * 2026_07_02 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'code',
    'name',
    'name_ar',
    'max_amount',
    'is_active',
    'sort_order',
])]
class CompReason extends Model
{
    use SoftDeletes;

    protected $table = 'pos_comp_reasons';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_amount' => 'decimal:3',
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

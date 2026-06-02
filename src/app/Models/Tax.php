<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Company-level tax (merchant-configurable VAT + other charges).
 *
 * A free-form name (e.g. "VAT", "Municipality") + a percentage rate, applied
 * company-wide (every branch). The Main POS fetches the active set via
 * /device/config at staff login and adds each one, as its own line, on top of
 * the order total (exclusive). Each merchant maintains their own list
 * (per-company tenancy); soft delete preserves historical order references.
 *
 * Schema owned by pos_admin's 2026_06_24_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'name_ar',
    'rate_percent',
    'is_active',
    'sort_order',
])]
class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_taxes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
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

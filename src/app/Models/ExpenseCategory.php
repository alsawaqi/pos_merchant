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
 * Company-level expense category (merchant-configurable; blueprint §5.10).
 *
 * A free-form name + a stable slug `key`. The POS expense-logging screen offers
 * the active set; logged expenses store the lowercase `key` string on
 * pos_expenses.category (so the key is immutable once minted). Each merchant
 * maintains their own list (per-company tenancy); soft delete preserves the row
 * so historical expenses stay resolvable.
 *
 * NOTE: a SEPARATE enum {@see \App\Enums\ExpenseCategory} models the legacy
 * fixed set on pos_expenses.category — this Models-namespace class is the
 * CRUD-backed table and must not be conflated with it.
 *
 * Schema owned by pos_admin (pos_expense_categories, live-migrated).
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'name_ar',
    'key',
    'is_active',
    'sort_order',
])]
class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $table = 'pos_expense_categories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

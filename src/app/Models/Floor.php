<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FloorStatus;
use Database\Factories\FloorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A logical area within a branch (Main Hall / Patio /
 * VIP / Drive-Thru). Holds {@see Table} rows.
 *
 * Schema owned by pos_admin's 2026_05_27_030000 migration;
 * pos_merchant reads + writes via the narrow whitelist below
 * via {@see \App\Actions\Pos\FloorPlan\*} actions.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'name',
    'name_ar',
    'display_order',
    'status',
])]
class Floor extends Model
{
    /** @use HasFactory<FloorFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_floors';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FloorStatus::class,
            'display_order' => 'integer',
        ];
    }

    /**
     * Auto-mint a uuid on create — the DB has it as UNIQUE so
     * a missing one would blow up under a parallel test seed.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $floor): void {
            if ($floor->uuid === null || $floor->uuid === '') {
                $floor->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Bind by uuid in the URL so internal ids stay out of
     * the address bar (consistent with Branch + PosStaff).
     */
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
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class)->orderBy('display_order')->orderBy('label');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FloorStatus::Active->value);
    }
}

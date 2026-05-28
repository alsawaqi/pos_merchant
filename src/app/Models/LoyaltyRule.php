<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyRuleStatus;
use App\Enums\LoyaltyRuleType;
use Database\Factories\LoyaltyRuleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Loyalty refactor — a loyalty rule (blueprint §5.8 + §10.6).
 *
 * visit_based (stamp card) or spend_based (points). config_json
 * carries the per-type configuration + the §5.8 restrictions
 * (see the migration docblock for the shape). Multiple rules can
 * be active in parallel; pause/resume without deleting.
 *
 * isActiveAt() composes status + validity window — the POS picker
 * (Phase 8) and EvaluateLoyalty use it to decide eligibility
 * before doing the earn/redeem math.
 *
 * Schema owned by pos_admin's 2026_06_08_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'type',
    'config_json',
    'validity_start',
    'validity_end',
    'status',
])]
class LoyaltyRule extends Model
{
    /** @use HasFactory<LoyaltyRuleFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_loyalty_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoyaltyRuleType::class,
            'config_json' => 'array',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'status' => LoyaltyRuleStatus::class,
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
     * @return HasMany<LoyaltyAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(LoyaltyAccount::class);
    }

    /**
     * status=active AND now() within the validity window. NULL on
     * either end = no bound there.
     */
    public function isActiveAt(DateTimeInterface $at): bool
    {
        if ($this->status !== LoyaltyRuleStatus::Active) {
            return false;
        }
        if ($this->validity_start !== null && $at < $this->validity_start) {
            return false;
        }
        if ($this->validity_end !== null && $at > $this->validity_end) {
            return false;
        }
        return true;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LoyaltyRuleStatus::Active->value);
    }
}

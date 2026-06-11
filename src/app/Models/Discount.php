<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use Database\Factories\DiscountFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 6d — discount rule (blueprint §5.9 + §10.7).
 *
 * Applicability is a 6-axis predicate (see class doc on the
 * migration). The model provides predicate helpers:
 *
 *   - isActiveAt($dateTime)        — status + validity window
 *   - matchesDay($dateTime)        — dayofweek bitmask
 *   - matchesTime($dateTime)       — time-of-day with midnight wrap
 *   - matchesBranch($branchId)     — branch_scope membership
 *
 * Phase 6d-4 evaluateDiscounts() composes these to decide if a
 * rule applies BEFORE doing the money math. The composite
 * helper `appliesAt($dateTime, $branchId)` does all four
 * predicates in one call for the common case.
 *
 * Schema owned by pos_admin's 2026_06_05_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'scope',
    'amount_type',
    'amount',
    'validity_start',
    'validity_end',
    'dayofweek_mask',
    'time_start',
    'time_end',
    'branch_scope_json',
    'stackable',
    'requires_manager_approval',
    'auto_apply',
    'status',
])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_discounts';

    /**
     * Bitmask values per the migration: Sun=1..Sat=64.
     *
     * @var array<int, int>
     */
    public const DOW_MASK = [
        0 => 1,  // Sunday (Carbon::SUNDAY = 0)
        1 => 2,  // Monday
        2 => 4,
        3 => 8,
        4 => 16,
        5 => 32,
        6 => 64, // Saturday
    ];

    public const DOW_ALL = 127; // 0b1111111

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => DiscountScope::class,
            'amount_type' => DiscountAmountType::class,
            'amount' => 'decimal:3',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'dayofweek_mask' => 'integer',
            'branch_scope_json' => 'array',
            'stackable' => 'boolean',
            'requires_manager_approval' => 'boolean',
            // P-F4: order-scope rules only — true = the device applies the
            // rule by itself to every qualifying order. product/category
            // scopes are ALWAYS automatic (per matching cart line); their
            // stored value is forced TRUE by the write actions and the
            // device ignores the flag for them.
            'auto_apply' => 'boolean',
            'status' => DiscountStatus::class,
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
     * Target rows (products/categories this discount applies to).
     * Empty for scope=order discounts.
     *
     * @return HasMany<DiscountTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }

    // =================== PREDICATES ===================

    /**
     * Status=active AND now() in validity window. NULL on either
     * end means "no bound on that end".
     */
    public function isActiveAt(DateTimeInterface $at): bool
    {
        if ($this->status !== DiscountStatus::Active) {
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
     * dayofweek_mask matches the given day-of-week. NULL mask
     * = every day.
     */
    public function matchesDay(DateTimeInterface $at): bool
    {
        $mask = $this->dayofweek_mask ?? self::DOW_ALL;
        $dow = (int) Carbon::instance($at)->dayOfWeek; // 0=Sun..6=Sat
        $bit = self::DOW_MASK[$dow] ?? 0;
        return ($mask & $bit) !== 0;
    }

    /**
     * time-of-day window. NULL start AND end = "all day".
     * Midnight wrap: if time_start > time_end (e.g. 22:00 →
     * 02:00), match the 22:00→24:00 OR 00:00→time_end window.
     */
    public function matchesTime(DateTimeInterface $at): bool
    {
        $start = $this->time_start;
        $end = $this->time_end;
        if ($start === null && $end === null) {
            return true;
        }
        $now = Carbon::instance($at)->format('H:i:s');
        // Default the missing bound to the natural edge.
        $start ??= '00:00:00';
        $end ??= '23:59:59';

        if ($start <= $end) {
            // Regular (non-wrapping) window.
            return $now >= $start && $now <= $end;
        }
        // Midnight wrap.
        return $now >= $start || $now <= $end;
    }

    /**
     * Branch scope membership. NULL = all branches.
     */
    public function matchesBranch(int $branchId): bool
    {
        $scope = $this->branch_scope_json;
        if ($scope === null || $scope === []) {
            return true;
        }
        return in_array($branchId, array_map('intval', $scope), true);
    }

    /**
     * Composite predicate — all four axes. Phase 6d-4
     * evaluateDiscounts() filters the candidate list with this
     * before doing the money math.
     */
    public function appliesAt(DateTimeInterface $at, int $branchId): bool
    {
        return $this->isActiveAt($at)
            && $this->matchesDay($at)
            && $this->matchesTime($at)
            && $this->matchesBranch($branchId);
    }

    // =================== SCOPES ===================

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DiscountStatus::Active->value);
    }
}

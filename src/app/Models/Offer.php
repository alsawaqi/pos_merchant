<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use Database\Factories\OfferFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * P-F9 — merchant offer / promotion rule.
 *
 * Each row is a `type` (bogo / bundle / multi_buy / cheapest_free /
 * spend_get) + a type-specific `config` JSON validated strictly by
 * {@see \App\Support\OfferConfig} — the pos_loyalty_rules pattern. The
 * POS DEVICE evaluates offers with a pure engine; the server only
 * stores/edits/emits and records applications on pos_order_discounts
 * (offer_id + name snapshot).
 *
 * Shared applicability axes mirror {@see Discount} exactly, including
 * the predicate helpers:
 *
 *   - isActiveAt($dateTime)        — status + validity window
 *   - matchesDay($dateTime)        — dayofweek bitmask (Sun=1..Sat=64)
 *   - matchesTime($dateTime)       — time-of-day with midnight wrap
 *   - matchesBranch($branchId)     — branch_scope membership
 *   - appliesAt($dateTime, $branchId) — all four composed
 *
 * Money inside `config` is integer BAISAS (the device wire convention).
 *
 * Schema owned by pos_admin's 2026_07_13_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'name',
    'name_ar',
    'type',
    'config',
    'auto_apply',
    'validity_start',
    'validity_end',
    'dayofweek_mask',
    'time_start',
    'time_end',
    'branch_scope_json',
    'max_per_order',
    'status',
])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_offers';

    /**
     * Bitmask values per the pos_discounts migration: Sun=1..Sat=64.
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
            'type' => OfferType::class,
            'config' => 'array',
            // true = the device applies the offer by itself to every
            // qualifying order; false = the cashier picks it. Bundle is
            // ALWAYS cashier-picked (write actions force false).
            'auto_apply' => 'boolean',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'dayofweek_mask' => 'integer',
            'branch_scope_json' => 'array',
            'max_per_order' => 'integer',
            'status' => OfferStatus::class,
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

    // =================== PREDICATES ===================
    // Mirrors of Discount's predicates — same axis semantics.

    /**
     * Status=active AND now() in validity window. NULL on either
     * end means "no bound on that end".
     */
    public function isActiveAt(DateTimeInterface $at): bool
    {
        if ($this->status !== OfferStatus::Active) {
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
     * Composite predicate — all four axes.
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
        return $query->where('status', OfferStatus::Active->value);
    }
}

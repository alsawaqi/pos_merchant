<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 6 backfill — Expense (blueprint §5.10 + §10.8).
 *
 * "Captured from POS": POS staff log on-the-spot expenses; the
 * merchant portal reviews them (approve / reject / annotate).
 * Until the POS app exists (Phase 9) a back-office portal user
 * can also log one directly — provenance is recorded in the two
 * nullable logged_by_* FKs (exactly one is set).
 *
 * Expenses feed the net-profit line of the Sales Report
 * (§5.11.1): recorded + reviewed count, rejected does not.
 *
 * No soft delete — a rejected expense is retained for the audit
 * trail and simply excluded from the rollup.
 *
 * Schema owned by pos_admin's 2026_06_07_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'category',
    'amount',
    'note',
    'receipt_photo_path',
    'logged_by_pos_staff_id',
    'logged_by_portal_user_id',
    'logged_at',
    'status',
    'reviewed_by_portal_user_id',
    'reviewed_at',
    'review_note',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected $table = 'pos_expenses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // v2 #7: category is now a free-form per-company key (a slug from
            // pos_expense_categories), no longer the fixed ExpenseCategory enum
            // — so custom categories never trip an "undefined enum case" cast.
            'category' => 'string',
            'amount' => 'decimal:3',
            'logged_at' => 'datetime',
            'status' => ExpenseStatus::class,
            'reviewed_at' => 'datetime',
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
    public function loggedByStaff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'logged_by_pos_staff_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function loggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_portal_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_portal_user_id');
    }

    /**
     * Counts toward the net-profit rollup? Rejected expenses do not.
     */
    public function countsTowardNetProfit(): bool
    {
        return $this->status !== ExpenseStatus::Rejected;
    }

    /**
     * The review queue's default filter — expenses awaiting a
     * merchant decision.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Recorded->value);
    }
}

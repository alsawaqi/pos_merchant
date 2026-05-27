<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RestockRequestStatus;
use Database\Factories\RestockRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Phase 5c — branch-to-HQ restock request.
 *
 * The header for a multi-line request. Lines live in
 * pos_restock_request_lines and are loaded via $this->lines.
 *
 * status drives the lifecycle (see RestockRequestStatus state
 * machine). All transitions go through their respective Actions
 * which validate that the source state is legal AND update the
 * relevant timestamp + actor columns atomically.
 *
 * Schema owned by pos_admin's 2026_05_31_010100 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'status',
    'requested_by_user_id',
    'submitted_at',
    'reviewed_by_user_id',
    'reviewed_at',
    'review_note',
    'fulfilled_at',
    'note',
])]
class RestockRequest extends Model
{
    /** @use HasFactory<RestockRequestFactory> */
    use HasFactory;

    protected $table = 'pos_restock_requests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RestockRequestStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Line items. Ordered by sort_order then id so the UI shows
     * them in the order the requester arranged them.
     *
     * @return HasMany<RestockRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RestockRequestLine::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RestockRequestStatus::Draft->value,
            RestockRequestStatus::Submitted->value,
            RestockRequestStatus::Approved->value,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchTargetPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * P-G8 — a branch sales target: [amount] per [period], evaluated over
 * back-to-back windows of [window_periods] periods starting at
 * [starts_on]. The check is CUMULATIVE inside a window (a weak day can
 * be saved by a strong one). One ACTIVE target per branch (Action-layer
 * rule — creating a new one deactivates the old, which keeps its window
 * history). amount + is_active are the only mutable fields; structural
 * changes (period / window size / anchor) are a REPLACE so finalized
 * history is never re-keyed.
 *
 * Schema owned by pos_admin's 2026_07_21_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'period',
    'amount',
    'window_periods',
    'starts_on',
    'is_active',
    'created_by_user_id',
])]
class BranchTarget extends Model
{
    use SoftDeletes;

    protected $table = 'pos_branch_targets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => BranchTargetPeriod::class,
            'amount' => 'decimal:3',
            'window_periods' => 'integer',
            'starts_on' => 'date',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Finished evaluation windows, newest first.
     *
     * @return HasMany<BranchTargetWindow, $this>
     */
    public function windows(): HasMany
    {
        return $this->hasMany(BranchTargetWindow::class, 'target_id')->orderByDesc('window_start');
    }
}

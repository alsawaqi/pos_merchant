<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G8 — one FINISHED evaluation window of a branch target: the goal
 * frozen at finalization, the actual confirmed sales, and the hit/miss
 * verdict. Written lazily (no scheduler exists) whenever the portal
 * reads targets or the dashboard performance widget — idempotent via
 * UNIQUE (target_id, window_start).
 */
#[Fillable([
    'target_id',
    'company_id',
    'branch_id',
    'window_start',
    'window_end',
    'goal_amount',
    'actual_amount',
    'hit',
    'finalized_at',
])]
class BranchTargetWindow extends Model
{
    protected $table = 'pos_branch_target_windows';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'goal_amount' => 'decimal:3',
            'actual_amount' => 'decimal:3',
            'hit' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BranchTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(BranchTarget::class, 'target_id');
    }
}

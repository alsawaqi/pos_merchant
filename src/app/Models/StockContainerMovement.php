<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH review add-on (B) — the breakdown's append-only ledger: one row per
 * change of a container balance (balance = Σ delta_pieces). Reasons: purchase,
 * allocation_in / allocation_out, transfer_in / transfer_out, count, correct,
 * waste, clamp (a take larger than the breakdown held), device_count.
 *
 * Schema owned by pos_admin (2026_10_06_100007).
 */
#[Fillable([
    'company_id',
    'branch_id',
    'ingredient_id',
    'container_id',
    'delta_pieces',
    'pieces_after',
    'reason',
    'stock_movement_id',
    'reference_type',
    'reference_id',
    'recorded_by_user_id',
    'recorded_by_pos_staff_id',
    'occurred_at',
])]
class StockContainerMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const REASONS = [
        'purchase', 'allocation_in', 'allocation_out', 'transfer_in', 'transfer_out',
        'count', 'correct', 'waste', 'clamp', 'device_count',
    ];

    protected $table = 'pos_stock_container_movements';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}

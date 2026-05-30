<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient line in a branch transfer.
 *
 * quantity is the amount moved; unit_at_set + unit_cost_at_time snapshot the
 * ingredient as it stood at transfer time. Composite-unique on
 * (branch_transfer_id, ingredient_id) — an ingredient appears once per transfer.
 *
 * Schema owned by pos_admin (2026_06_13_010000).
 */
#[Fillable([
    'branch_transfer_id',
    'ingredient_id',
    'quantity',
    'unit_at_set',
    'unit_cost_at_time',
])]
class BranchTransferLine extends Model
{
    protected $table = 'pos_branch_transfer_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_at_set' => IngredientUnit::class,
            'unit_cost_at_time' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<BranchTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransfer::class, 'branch_transfer_id');
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}

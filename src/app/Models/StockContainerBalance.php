<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH review add-on (B) — how many of one LEAF container of an item are on
 * a shelf: a branch (branch_id) or the warehouse (branch_id NULL). This is the
 * breakdown shown under the live total ("2 × bottle 1.5 l + 3 × bottle 500
 * ml"). It is what was there at the last purchase, transfer or count — recipe
 * use lowers only the total — and it is NEVER used to compute stock.
 *
 * Written only through {@see \App\Actions\Pos\Inventory\ContainerBreakdownAction},
 * which keeps the ledger (pos_stock_container_movements) in step.
 * Schema owned by pos_admin (2026_10_06_100007).
 */
#[Fillable([
    'company_id',
    'branch_id',
    'ingredient_id',
    'container_id',
    'pieces',
])]
class StockContainerBalance extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_stock_container_balances';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pieces' => ScaledDecimal::class.':0,4',
        ];
    }

    /**
     * @return BelongsTo<IngredientAltUnit, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(IngredientAltUnit::class, 'container_id')->withTrashed();
    }
}

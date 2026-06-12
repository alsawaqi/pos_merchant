<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P-G4 — central company warehouse balance for an ingredient, the ingredient
 * twin of {@see ProductStock}. One row per (company, ingredient); `quantity`
 * is the warehouse pool held before distributing stock to branches
 * (per-branch balances stay on pos_branch_stock). Schema owned by pos_admin
 * (2026_07_18_000000_add_central_ingredient_warehouse).
 */
#[Fillable([
    'company_id',
    'ingredient_id',
    'quantity',
    'last_movement_at',
])]
class IngredientStock extends Model
{
    protected $table = 'pos_ingredient_stock';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}

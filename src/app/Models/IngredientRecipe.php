<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Pos\Catalogue\SavePrepItemAction;
use App\Casts\ScaledDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH-P3 P3-4 — one line of a PREP ITEM's recipe (what one batch is made
 * of): a component (raw ingredient or another prep item) and its quantity
 * per batch in the COMPONENT's base unit. entered_unit / entered_quantity
 * record how the line was typed (P3-1) so the editor reopens it unchanged.
 *
 * Written only by the prep-item actions ({@see SavePrepItemAction}),
 * which enforce: same company, at most 3 prep levels, no cycles, and a
 * quantity that does not round to 0 in the base unit.
 *
 * Schema owned by pos_admin's 2026_10_02_100002 migration.
 */
#[Fillable([
    'prep_ingredient_id',
    'ingredient_id',
    'quantity',
    'entered_unit',
    'entered_quantity',
    'sort_order',
])]
class IngredientRecipe extends Model
{
    protected $table = 'pos_ingredient_recipes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => ScaledDecimal::class.':3,4',
            'entered_quantity' => ScaledDecimal::class.':0,4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function prepItem(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'prep_ingredient_id')->withTrashed();
    }

    /**
     * The component (soft-deleted rows included: a recipe keeps naming what
     * it was made of).
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }
}

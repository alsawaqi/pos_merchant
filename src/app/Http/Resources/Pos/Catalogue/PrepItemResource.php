<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Support\Catalogue\AllergenSync;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\RecipeQuantity;
use App\Support\StockDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LAUNCH-P3 P3-4 — a prep item for its editor and list: yield, recipe lines
 * (base + entered unit, line cost per batch), the live cost per base unit and
 * per batch (both from {@see PrepGraph}), its nesting depth and where it is
 * used. `used_by` is set by the controller (additional data) when known.
 *
 * @mixin Ingredient
 */
class PrepItemResource extends JsonResource
{
    /** @var array{product_recipes: int, addon_lines: int, prep_recipes: int}|null */
    public ?array $usedBy = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $graph = PrepGraph::forCompany((int) $this->company_id);
        $quantities = app(RecipeQuantity::class);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'unit' => $this->unit?->value,
            'is_prep' => true,
            'prep_yield_quantity' => (string) $this->prep_yield_quantity,
            'status' => $this->status,
            // LAUNCH costs & allergens add-on — ticked on the prep item, and
            // with everything its recipe brings (every level).
            'allergens' => AllergenSync::graph((int) $this->company_id)->ownIngredient((int) $this->id),
            'allergens_all' => AllergenSync::graph((int) $this->company_id)->ingredient((int) $this->id),
            'unit_cost' => $graph->unitCost((int) $this->id),
            // LAUNCH review add-on (A1) — every raw ingredient has a cost.
            'cost_complete' => $graph->costComplete((int) $this->id),
            'batch_cost' => (string) StockDecimal::unitCost((string) $graph->batchCostExact((int) $this->id)->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP)),
            'depth' => $graph->depth((int) $this->id),
            'lines' => $this->whenLoaded('prepRecipeLines', fn (): array => $this->prepRecipeLines->map(function (IngredientRecipe $line) use ($graph, $quantities): array {
                $ingredient = $line->ingredient;
                $entered = $ingredient !== null
                    ? $quantities->display($ingredient, (string) $line->quantity, $line->entered_unit, $line->entered_quantity)
                    : ['entered' => false, 'unit' => null, 'quantity' => null];

                return [
                    'ingredient' => $ingredient === null ? null : [
                        'id' => $ingredient->id,
                        'uuid' => $ingredient->uuid,
                        'name' => $ingredient->name,
                        'name_ar' => $ingredient->name_ar,
                        'unit' => $ingredient->unit?->value,
                        'is_prep' => (bool) $ingredient->is_prep,
                        'default_unit_cost' => $graph->unitCost((int) $ingredient->id),
                        // LAUNCH review add-on (A1) — false = "No cost yet".
                        'has_cost' => $graph->costComplete((int) $ingredient->id),
                        'piece_unit_label' => $ingredient->piece_unit_label,
                        'deleted' => $ingredient->deleted_at !== null,
                    ],
                    // BASE quantity per batch, in the component's base unit.
                    'quantity' => (string) $line->quantity,
                    'entered_unit' => $entered['entered'] ? $entered['unit'] : null,
                    'entered_quantity' => $entered['entered'] ? $entered['quantity'] : null,
                    'line_cost' => (string) StockDecimal::unitCost((string) $graph->linesCostExact([(int) $line->ingredient_id => (string) $line->quantity])->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP)),
                ];
            })->values()->all()),
            'used_by' => $this->usedBy,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

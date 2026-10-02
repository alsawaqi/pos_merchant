<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\ProductRecipe;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\RecipeQuantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductRecipe
 */
class ProductRecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // LAUNCH-P3 P3-1 — how the line was typed, while it still converts to
        // the stored base quantity (else NULL: the editor shows the base).
        $entered = $this->resource->relationLoaded('ingredient') && $this->ingredient !== null
            ? app(RecipeQuantity::class)->display($this->ingredient, (string) $this->quantity, $this->entered_unit, $this->entered_quantity)
            : ['entered' => false, 'unit' => null, 'quantity' => null];

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'ingredient_id' => $this->ingredient_id,
            // BASE quantity + unit (what the device deducts).
            'quantity' => (string) $this->quantity,
            'unit_at_set' => $this->unit_at_set?->value,
            'sort_order' => $this->sort_order,
            'entered_unit' => $entered['entered'] ? $entered['unit'] : null,
            'entered_quantity' => $entered['entered'] ? $entered['quantity'] : null,
            // Ingredient summary inlined when eager-loaded so
            // the UI doesn't need a second round-trip per row.
            'ingredient' => $this->whenLoaded('ingredient', fn (): ?array => $this->ingredient === null ? null : [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'name_ar' => $this->ingredient->name_ar,
                'unit' => $this->ingredient->unit?->value,
                // LAUNCH-P3 P3-4 — a prep item costs what its recipe costs.
                'is_prep' => (bool) $this->ingredient->is_prep,
                'default_unit_cost' => $this->ingredient->is_prep
                    ? PrepGraph::forCompany((int) $this->ingredient->company_id)->unitCost((int) $this->ingredient->id)
                    : (string) $this->ingredient->default_unit_cost,
                'piece_unit_label' => $this->ingredient->piece_unit_label,
                'piece_unit_label_ar' => $this->ingredient->piece_unit_label_ar,
            ]),
        ];
    }
}

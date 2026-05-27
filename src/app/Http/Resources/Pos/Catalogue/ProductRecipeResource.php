<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\ProductRecipe;
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
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'ingredient_id' => $this->ingredient_id,
            'quantity' => (string) $this->quantity,
            'unit_at_set' => $this->unit_at_set?->value,
            'sort_order' => $this->sort_order,
            // Ingredient summary inlined when eager-loaded so
            // the UI doesn't need a second round-trip per row.
            'ingredient' => $this->whenLoaded('ingredient', fn (): ?array => $this->ingredient === null ? null : [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'name_ar' => $this->ingredient->name_ar,
                'unit' => $this->ingredient->unit?->value,
                'default_unit_cost' => (string) $this->ingredient->default_unit_cost,
            ]),
        ];
    }
}

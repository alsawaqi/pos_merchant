<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\BranchStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BranchStock
 */
class BranchStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'ingredient_id' => $this->ingredient_id,
            // Quantity as string (decimal:3) for OMR-baisas
            // precision parity through the JSON layer.
            'quantity' => (string) $this->quantity,
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
            // Healthy / Low / Critical — driven by the model's
            // healthLevel() helper which reads the ingredient
            // threshold. Cached here so the UI doesn't have to
            // duplicate the math.
            'health_level' => $this->healthLevel(),
            // Ingredient summary inlined so the list view
            // doesn't need a second round-trip per row.
            'ingredient' => $this->whenLoaded('ingredient', fn (): array => [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'name_ar' => $this->ingredient->name_ar,
                'unit' => $this->ingredient->unit?->value,
                'default_unit_cost' => (string) $this->ingredient->default_unit_cost,
                'min_stock_threshold' => $this->ingredient->min_stock_threshold !== null
                    ? (string) $this->ingredient->min_stock_threshold
                    : null,
            ]),
        ];
    }
}

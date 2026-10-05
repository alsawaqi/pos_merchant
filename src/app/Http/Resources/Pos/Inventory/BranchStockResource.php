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
            // NULL when the branch has never stocked the ingredient
            // (LAUNCH-P2: the list shows every ingredient, missing = 0).
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'ingredient_id' => $this->ingredient_id,
            // Quantity as string (up to 4 decimals since LAUNCH-P2) for
            // precision parity through the JSON layer.
            'quantity' => (string) $this->quantity,
            'last_movement_at' => $this->last_movement_at?->toIso8601String(),
            // Healthy / Low / Critical — driven by the model's
            // healthLevel() helper which reads the ingredient
            // threshold. Cached here so the UI doesn't have to
            // duplicate the math.
            'health_level' => $this->healthLevel(),
            // LAUNCH-P2 P2-7 — sell, but warn: negative (red) /
            // below_minimum (amber) / ok, and the stock value at the
            // weighted-average cost (quantity × cost, 3dp).
            'stock_status' => $this->stockStatus(),
            'stock_value' => $this->stockValue(),
            'has_stock_row' => $this->exists,
            // LAUNCH review add-on (B2) — the breakdown by container under the
            // live total, and when it was last counted (or a total-only count).
            'breakdown' => $this->resource->breakdown ?? [],
            'containers_counted_at' => $this->containers_counted_at?->toIso8601String(),
            'containers_total_count_at' => $this->containers_total_count_at?->toIso8601String(),
            // Ingredient summary inlined so the list view
            // doesn't need a second round-trip per row.
            'ingredient' => $this->whenLoaded('ingredient', fn (): array => [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'name_ar' => $this->ingredient->name_ar,
                'unit' => $this->ingredient->unit?->value,
                'default_unit_cost' => (string) $this->ingredient->default_unit_cost,
                // LAUNCH review add-on (A1) — false = "No cost yet".
                'has_cost' => $this->ingredient->hasCost(),
                'min_stock_threshold' => $this->ingredient->min_stock_threshold !== null
                    ? (string) $this->ingredient->min_stock_threshold
                    : null,
            ]),
        ];
    }
}

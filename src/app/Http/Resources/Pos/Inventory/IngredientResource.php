<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'unit' => $this->unit?->value,
            // Money + threshold as strings (decimal:3 cast).
            'default_unit_cost' => (string) $this->default_unit_cost,
            'min_stock_threshold' => $this->min_stock_threshold !== null
                ? (string) $this->min_stock_threshold
                : null,
            'primary_supplier_id' => $this->primary_supplier_id,
            'primary_supplier' => $this->whenLoaded('primarySupplier', function () {
                return $this->primarySupplier === null ? null : [
                    'id' => $this->primarySupplier->id,
                    'uuid' => $this->primarySupplier->uuid,
                    'name' => $this->primarySupplier->name,
                ];
            }),
            'status' => $this->status,
            // v2 #13 — alternate units (when loaded), so the ingredient form can
            // render its unit list without a second round-trip.
            'alt_units' => IngredientAltUnitResource::collection($this->whenLoaded('altUnits')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

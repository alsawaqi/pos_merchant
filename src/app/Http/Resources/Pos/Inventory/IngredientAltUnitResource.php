<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\IngredientAltUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IngredientAltUnit
 */
class IngredientAltUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'ingredient_id' => $this->ingredient_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            // decimal:4 cast → string, so the factor keeps full precision.
            'factor' => (string) $this->factor,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

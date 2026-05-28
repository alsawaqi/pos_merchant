<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\RestockRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestockRequestLine
 */
class RestockRequestLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'restock_request_id' => $this->restock_request_id,
            'ingredient_id' => $this->ingredient_id,
            'quantity_requested' => (string) $this->quantity_requested,
            'quantity_allocated' => (string) $this->quantity_allocated,
            'unit_at_set' => $this->unit_at_set?->value,
            'note' => $this->note,
            'sort_order' => $this->sort_order,
            // Ingredient summary inlined for the UI's per-line
            // rendering. Tolerates a NULL relation in the corner
            // case where the ingredient was soft-deleted AFTER
            // a terminal-state request (the open-state guard
            // blocks this for active requests).
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

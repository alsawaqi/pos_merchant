<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\AddOnGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AddOnGroup
 */
class AddOnGroupResource extends JsonResource
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
            'selection_mode' => $this->selection_mode?->value,
            'is_global' => (bool) $this->is_global,
            'display_order' => $this->display_order,
            'status' => $this->status,
            // products_count surfaces when the controller did
            // withCount('products') — lets the UI show "attached
            // to N products" on the list without a separate
            // round-trip.
            'products_count' => $this->whenCounted('products'),
            'addons_count' => $this->whenCounted('addOns'),
            // addOns inlined when the controller eager-loads them
            // (the edit modal needs the option list).
            'addons' => AddOnResource::collection($this->whenLoaded('addOns')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

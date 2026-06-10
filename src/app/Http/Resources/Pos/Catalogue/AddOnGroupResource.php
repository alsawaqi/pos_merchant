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
            // Phase B — selection constraints (NULL = unbounded; min>=1 = required).
            'min_selections' => $this->min_selections !== null ? (int) $this->min_selections : null,
            'max_selections' => $this->max_selections !== null ? (int) $this->max_selections : null,
            // Phase B — bound category ids (when eager-loaded).
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->all()),
            'is_global' => (bool) $this->is_global,
            // v2 #6: non-null = a group privately owned by this product.
            'owner_product_id' => $this->owner_product_id !== null ? (int) $this->owner_product_id : null,
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

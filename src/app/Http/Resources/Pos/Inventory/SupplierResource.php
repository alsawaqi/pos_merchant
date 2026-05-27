<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
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
            'contact' => $this->contact,
            'notes' => $this->notes,
            'status' => $this->status,
            // ingredients_count surfaces when the controller did
            // withCount('ingredients') — used by the delete-
            // disabled-when-in-use UX.
            'ingredients_count' => $this->whenCounted('ingredients'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

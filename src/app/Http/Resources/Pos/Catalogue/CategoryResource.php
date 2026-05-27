<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see ProductCategory} for the catalogue
 * page.
 *
 * `products_count` comes from withCount('products') when the
 * controller listed categories; falls back to a cheap count
 * when the category was loaded individually.
 *
 * @mixin ProductCategory
 */
class CategoryResource extends JsonResource
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
            'description' => $this->description,
            'image_url' => $this->image_url,
            'display_order' => $this->display_order,
            'status' => $this->status?->value,
            'products_count' => $this->products_count
                ?? $this->products()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

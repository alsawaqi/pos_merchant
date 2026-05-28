<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\DeliveryProviders;

use App\Models\DeliveryProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryProvider
 */
class DeliveryProviderResource extends JsonResource
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
            'color' => $this->color,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            // prices_count surfaces when the controller did
            // withCount('prices') — used by the list page to
            // tell the merchant how many products are configured
            // for this provider.
            'prices_count' => $this->whenCounted('prices'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

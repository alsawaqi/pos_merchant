<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\DeliveryProviders;

use App\Models\ProductDeliveryPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductDeliveryPrice
 */
class ProductDeliveryPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'delivery_provider_id' => $this->delivery_provider_id,
            // OMR decimal:3 as a string — frontend treats as
            // opaque (never parseFloat).
            'price' => (string) $this->price,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Provider summary inlined when the controller
            // eager-loaded the relation. Lets the product
            // modal render "Talabat: 6.000" without an extra
            // round-trip per row.
            'delivery_provider' => $this->whenLoaded('deliveryProvider', fn (): ?array => $this->deliveryProvider === null ? null : [
                'id' => $this->deliveryProvider->id,
                'uuid' => $this->deliveryProvider->uuid,
                'name' => $this->deliveryProvider->name,
                'color' => $this->deliveryProvider->color,
            ]),
        ];
    }
}

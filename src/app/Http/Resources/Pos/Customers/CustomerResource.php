<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Customers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
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
            'phone' => $this->phone,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // vehicle_plates surfaces when the controller did
            // ->load('vehiclePlates'). The list page can cheaply
            // include them; the show page always does.
            'vehicle_plates' => CustomerVehiclePlateResource::collection($this->whenLoaded('vehiclePlates')),
            // Convenience count for the list page's "N plates"
            // chip; surfaces when the controller did
            // withCount('vehiclePlates').
            'vehicle_plates_count' => $this->whenCounted('vehiclePlates'),
        ];
    }
}

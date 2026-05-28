<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Customers;

use App\Models\CustomerVehiclePlate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerVehiclePlate
 */
class CustomerVehiclePlateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'customer_id' => $this->customer_id,
            // plate_number is stored canonical (trimmed +
            // uppercased) by AttachVehiclePlateAction; the
            // resource just echoes the stored form.
            'plate_number' => $this->plate_number,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

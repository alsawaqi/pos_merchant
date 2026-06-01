<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Branch;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only projection of a {@see Device} for the merchant's
 * "devices on this branch" view. Devices are provisioned + controlled
 * by the platform admin; the merchant only SEES them here (type,
 * status, last seen) -- there is no control surface.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
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
            'serial_number' => $this->serial_number,
            'kiosk_id' => $this->kiosk_id,
            'device_type' => $this->device_type,
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}

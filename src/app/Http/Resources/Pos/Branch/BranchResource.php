<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Branch;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full projection of a {@see Branch} for the merchant portal.
 *
 * Exposes every merchant-editable field (so the Edit modal can
 * pre-populate without a follow-up fetch) plus the immutable
 * identifiers the UI displays for context (code, uuid,
 * country/region/etc.).
 *
 * Geo decimals are returned as strings (Laravel's decimal cast
 * returns strings by default) — the frontend re-parses them when
 * binding to the map picker.
 *
 * @mixin Branch
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            // Display-only identifier — merchant cannot edit it.
            'code' => $this->code,

            // Editable text fields.
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'manager_name' => $this->manager_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,

            // Geo — admin owns the country/region/district/city
            // catalogue ids; merchant tweaks lat/lng and
            // geofence radius for staff attendance.
            'country_id' => $this->country_id,
            'region_id' => $this->region_id,
            'district_id' => $this->district_id,
            'city_id' => $this->city_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_radius_m' => $this->geofence_radius_m,

            // Operational.
            'opening_hours_json' => $this->opening_hours_json,
            'default_order_type' => $this->default_order_type?->value,
            'status' => $this->status?->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

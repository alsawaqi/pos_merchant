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
            // Denormalised wallet (store-credit) balance —
            // decimal:3 OMR string. Frontend treats it as opaque
            // (never parseFloat for OMR — baisas precision matters).
            // Points moved to per-rule pos_loyalty_accounts in the
            // loyalty refactor; fetch them via /customers/{uuid}/loyalty.
            'wallet_balance' => (string) $this->wallet_balance,
            // Phase D3 — CRM profile fields (§5.7.2). Tags are a
            // flat array of trimmed strings (NULL column → []);
            // dob is date-only Y-m-d. upcoming_birthday = birthday
            // (month+day) within the next 30 days, today included,
            // timezone-naive — powers the list's cake indicator
            // without the frontend re-deriving year-wrap logic.
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'tags' => $this->tags_json ?? [],
            'upcoming_birthday' => $this->upcomingBirthday(),
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

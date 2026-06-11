<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Offers;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P-F9 — offer / promotion rule (mirrors DiscountResource).
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
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
            'type' => $this->type?->value,
            // The canonical per-type shape (money inside is integer baisas).
            'config' => $this->config,
            // true = the device applies the offer by itself; false = the
            // cashier picks it. Always false for bundle (server-forced).
            'auto_apply' => (bool) $this->auto_apply,
            'validity_start' => $this->validity_start?->toIso8601String(),
            'validity_end' => $this->validity_end?->toIso8601String(),
            'dayofweek_mask' => $this->dayofweek_mask !== null ? (int) $this->dayofweek_mask : null,
            'time_start' => $this->time_start,
            'time_end' => $this->time_end,
            'branch_scope_json' => $this->branch_scope_json,
            'max_per_order' => $this->max_per_order !== null ? (int) $this->max_per_order : null,
            'status' => $this->status?->value,
            // Computed convenience flag for the list page chip — composes
            // status + the validity window (the DiscountResource pattern).
            'currently_active' => $this->isActiveAt(now()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

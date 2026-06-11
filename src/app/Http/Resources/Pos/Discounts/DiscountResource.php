<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Discounts;

use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Discount
 */
class DiscountResource extends JsonResource
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
            'scope' => $this->scope?->value,
            'amount_type' => $this->amount_type?->value,
            'amount' => (string) $this->amount,
            'validity_start' => $this->validity_start?->toIso8601String(),
            'validity_end' => $this->validity_end?->toIso8601String(),
            'dayofweek_mask' => $this->dayofweek_mask !== null ? (int) $this->dayofweek_mask : null,
            'time_start' => $this->time_start,
            'time_end' => $this->time_end,
            'branch_scope_json' => $this->branch_scope_json,
            'stackable' => (bool) $this->stackable,
            'requires_manager_approval' => (bool) $this->requires_manager_approval,
            // P-F4 — order-scope rules only: true = the device applies the
            // rule by itself to every qualifying order. Always true for
            // product/category scopes (forced by the write actions; the
            // device ignores it there — targeted rules stay automatic).
            'auto_apply' => (bool) $this->auto_apply,
            'status' => $this->status?->value,
            // Computed convenience flag for the list page chip:
            // "active right now" composes status + the validity
            // window predicate. UI uses this to render an
            // amber "scheduled" badge or a grey "expired" badge
            // distinct from the merchant-set status.
            'currently_active' => $this->isActiveAt(now()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'targets' => DiscountTargetResource::collection($this->whenLoaded('targets')),
            // targets_count surfaces when controller did
            // withCount('targets').
            'targets_count' => $this->whenCounted('targets'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\FloorPlan;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see Table} for the floor plan UI.
 *
 * qr_token IS exposed in the merchant portal payload (the
 * SuperAdmin needs to copy / print it). It's NOT a secret
 * in the same sense as a password — it's a public bearer
 * token printed on customer-facing cards. The card-stealing
 * mitigation is RegenerateTableQrAction, not hiding the
 * token from the portal owner.
 *
 * @mixin Table
 */
class TableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'floor_id' => $this->floor_id,
            'label' => $this->label,
            'seats' => $this->seats,
            'min_party' => $this->min_party,
            'max_party' => $this->max_party,
            'shape' => $this->shape?->value,
            'notes' => $this->notes,
            'qr_token' => $this->qr_token,
            'status' => $this->status?->value,
            'display_order' => $this->display_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

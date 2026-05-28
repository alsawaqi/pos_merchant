<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\CustomerPointLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerPointLedgerEntry
 */
class PointLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'entry_type' => $this->entry_type?->value,
            // SIGNED integer — positive on inflow, negative on
            // outflow. Frontend treats as int (not money).
            'points_delta' => (int) $this->points_delta,
            'balance_after' => (int) $this->balance_after,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?array => $this->recordedBy === null ? null : [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),
        ];
    }
}

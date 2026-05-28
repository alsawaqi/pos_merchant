<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\WasteRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WasteRecord
 */
class WasteRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'branch_id' => $this->branch_id,
            'ingredient_id' => $this->ingredient_id,
            // Always-positive — the matching stock_movement holds
            // the signed-negative version.
            'quantity' => (string) $this->quantity,
            'reason' => $this->reason?->value,
            'unit_at_set' => $this->unit_at_set?->value,
            'unit_cost_at_time' => (string) $this->unit_cost_at_time,
            // Computed per-event cost. Frontend never multiplies
            // money on the client (decimal-string is the contract).
            'total_cost' => $this->totalCost(),
            'notes' => $this->notes,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'ingredient' => $this->whenLoaded('ingredient', fn (): ?array => $this->ingredient === null ? null : [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'name_ar' => $this->ingredient->name_ar,
                'unit' => $this->ingredient->unit?->value,
            ]),
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?array => $this->recordedBy === null ? null : [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),
        ];
    }
}

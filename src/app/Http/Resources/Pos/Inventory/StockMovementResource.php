<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'ingredient_id' => $this->ingredient_id,
            'movement_type' => $this->movement_type?->value,
            'quantity' => (string) $this->quantity,
            'unit_cost_at_time' => (string) $this->unit_cost_at_time,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recordedByUser', fn (): ?array => $this->recordedByUser === null ? null : [
                'id' => $this->recordedByUser->id,
                'name' => $this->recordedByUser->name,
                'kind' => 'portal_user',
            ]),
            'ingredient' => $this->whenLoaded('ingredient', fn (): array => [
                'id' => $this->ingredient->id,
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'unit' => $this->ingredient->unit?->value,
            ]),
        ];
    }
}

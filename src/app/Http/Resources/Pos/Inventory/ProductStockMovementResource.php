<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\ProductStockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see ProductStockMovement} ledger row. branch_name is null
 * for central-pool movements (branch_id NULL).
 *
 * @mixin ProductStockMovement
 */
class ProductStockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type instanceof ProductStockMovementType
                ? $this->movement_type->value
                : (string) $this->movement_type,
            'quantity' => (string) $this->quantity,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'note' => $this->note,
            'recorded_by' => $this->recordedByUser?->name,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}

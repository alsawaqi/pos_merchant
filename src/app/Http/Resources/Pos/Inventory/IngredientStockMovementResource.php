<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P-G4 — projection of a {@see StockMovement} ledger row for the ingredient
 * Stock dialog, shaped like {@see ProductStockMovementResource} (branch_name
 * null = a central-warehouse movement). The Inventory page's per-branch
 * Movements tab keeps using the richer {@see StockMovementResource}.
 *
 * @mixin StockMovement
 */
class IngredientStockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type instanceof StockMovementType
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

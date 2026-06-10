<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\IngredientPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase A — one purchase batch row (Additions §2.4).
 *
 * @mixin IngredientPurchase
 */
class IngredientPurchaseResource extends JsonResource
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
            'branch' => $this->whenLoaded('branch', fn () => [
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'ingredient_id' => $this->ingredient_id,
            'ingredient' => $this->whenLoaded('ingredient', fn () => [
                'uuid' => $this->ingredient->uuid,
                'name' => $this->ingredient->name,
                'unit' => $this->ingredient->unit?->value,
                'piece_unit_label' => $this->ingredient->piece_unit_label,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'uuid' => $this->supplier->uuid,
                'name' => $this->supplier->name,
            ]),
            'pieces_received' => $this->pieces_received !== null ? (string) $this->pieces_received : null,
            'units_received' => (string) $this->units_received,
            'total_paid' => (string) $this->total_paid,
            'unit_cost' => (string) $this->unit_cost,
            'units_per_piece_at_purchase' => $this->units_per_piece_at_purchase !== null
                ? (string) $this->units_per_piece_at_purchase
                : null,
            'is_loose' => (bool) $this->is_loose,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}

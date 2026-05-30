<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\BranchTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BranchTransfer
 */
class BranchTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'from_branch_id' => $this->from_branch_id,
            'from_branch_name' => $this->whenLoaded('fromBranch', fn () => $this->fromBranch->name),
            'to_branch_id' => $this->to_branch_id,
            'to_branch_name' => $this->whenLoaded('toBranch', fn () => $this->toBranch->name),
            'transferred_at' => $this->transferred_at?->toIso8601String(),
            'note' => $this->note,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'ingredient_id' => $line->ingredient_id,
                'ingredient_name' => $line->relationLoaded('ingredient') && $line->ingredient !== null
                    ? $line->ingredient->name
                    : null,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit_at_set?->value,
                'unit_cost_at_time' => (string) $line->unit_cost_at_time,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

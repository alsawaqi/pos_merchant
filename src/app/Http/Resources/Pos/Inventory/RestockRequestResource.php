<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\RestockRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestockRequest
 */
class RestockRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status?->value,
            // Derived from the enum's isTerminal(). The UI uses
            // this to hide action buttons on dead rows.
            'is_terminal' => $this->status?->isTerminal() ?? false,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'requested_by' => $this->whenLoaded('requestedBy', fn (): ?array => $this->requestedBy === null ? null : [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn (): ?array => $this->reviewedBy === null ? null : [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            'lines' => RestockRequestLineResource::collection($this->whenLoaded('lines')),
            // Convenience totals computed at render time so the
            // UI doesn't have to fold over lines twice. Returns
            // 0 / '0.000' when lines aren't loaded — safer
            // default than throwing.
            'totals' => $this->whenLoaded('lines', fn (): array => $this->buildTotals()),
        ];
    }

    /**
     * Sum of requested + allocated quantities + monetary cost.
     * All decimal-3 strings. Money never floats through JSON.
     *
     * @return array<string, string|int>
     */
    private function buildTotals(): array
    {
        $reqQty = 0.0;
        $allocQty = 0.0;
        $allocCost = 0.0;
        $lineCount = 0;
        foreach ($this->lines as $line) {
            $lineCount++;
            $reqQty += (float) $line->quantity_requested;
            $allocated = (float) $line->quantity_allocated;
            $allocQty += $allocated;
            // Use the ingredient's CURRENT default cost — this
            // is a UI summary, not a historical-COGS number.
            // (Historical COGS lives on the stock_movement's
            // unit_cost_at_time.)
            $unitCost = $line->ingredient !== null
                ? (float) $line->ingredient->default_unit_cost
                : 0.0;
            $allocCost += $allocated * $unitCost;
        }
        return [
            'line_count' => $lineCount,
            'quantity_requested' => number_format($reqQty, 3, '.', ''),
            'quantity_allocated' => number_format($allocQty, 3, '.', ''),
            'allocated_cost' => number_format($allocCost, 3, '.', ''),
        ];
    }
}

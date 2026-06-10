<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase A — a day-end stock count with its per-ingredient lines
 * (Additions §2.8).
 *
 * @mixin StockCount
 */
class StockCountResource extends JsonResource
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
            'note' => $this->note,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'recorded_by' => $this->recordedByUser?->name ?? $this->recordedByPosStaff?->name,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                static fn (StockCountLine $line): array => [
                    'ingredient_id' => $line->ingredient_id,
                    'ingredient' => $line->relationLoaded('ingredient') ? [
                        'uuid' => $line->ingredient->uuid,
                        'name' => $line->ingredient->name,
                        'name_ar' => $line->ingredient->name_ar,
                        'unit' => $line->ingredient->unit?->value,
                        'piece_unit_label' => $line->ingredient->piece_unit_label,
                    ] : null,
                    'counted_pieces' => $line->counted_pieces !== null ? (string) $line->counted_pieces : null,
                    'counted_units' => (string) $line->counted_units,
                    'expected_units' => (string) $line->expected_units,
                    'variance_units' => (string) $line->variance_units,
                    'unit_cost_at_time' => (string) $line->unit_cost_at_time,
                    'variance_value' => number_format(
                        (float) $line->variance_units * (float) $line->unit_cost_at_time,
                        3,
                        '.',
                        '',
                    ),
                ],
            )->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

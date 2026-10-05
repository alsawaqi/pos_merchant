<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Enums\MerchantPermission;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase A — a day-end stock count with its per-ingredient lines
 * (Additions §2.8).
 *
 * LAUNCH-P2 P2-6 — counts are blind: the book side of a line (expected,
 * variance and their value) is shown only after submit, and only to users
 * who may see stock values (inventory.view). Everyone else gets back what
 * they counted. late_movement_units shows how much of the expected figure
 * came from movements dated before the count that reached the books later.
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
        $canSeeStock = (bool) $request->user()?->can(MerchantPermission::InventoryView->value);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'branch_id' => $this->branch_id,
            'note' => $this->note,
            'counted_at' => $this->counted_at?->toIso8601String(),
            'recorded_by' => $this->recordedByUser?->name ?? $this->recordedByPosStaff?->name,
            'shows_stock_values' => $canSeeStock,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                static function (StockCountLine $line) use ($canSeeStock): array {
                    $row = [
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
                        // LAUNCH review add-on (D2) — what was counted by container.
                        'containers' => ($line->relationLoaded('containers') ? $line->containers : $line->containers()->get())
                            ->map(static fn ($c): array => [
                                'container_label' => $c->container_label,
                                'pieces' => \App\Support\Inventory\Containers::trim((string) $c->pieces),
                            ])->all(),
                    ];
                    if (! $canSeeStock) {
                        return $row;
                    }

                    return $row + [
                        'expected_units' => (string) $line->expected_units,
                        'variance_units' => (string) $line->variance_units,
                        'late_movement_units' => (string) ($line->late_movement_units ?? '0.000'),
                        'unit_cost_at_time' => (string) $line->unit_cost_at_time,
                        // Money: rounded ONCE, after multiplying the 4dp
                        // quantity by the 6dp unit cost.
                        'variance_value' => number_format(
                            (float) $line->variance_units * (float) $line->unit_cost_at_time,
                            3,
                            '.',
                            '',
                        ),
                    ];
                },
            )->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

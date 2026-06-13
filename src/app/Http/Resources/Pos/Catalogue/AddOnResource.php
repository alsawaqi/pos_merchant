<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\AddOn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AddOn
 */
class AddOnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'add_on_group_id' => $this->add_on_group_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            // Money as string — decimal:3 cast preserves
            // precision (NEVER parseFloat for money).
            'price_delta' => (string) $this->price_delta,
            // Phase B — pre-selected in the POS customize sheet.
            'is_default' => (bool) $this->is_default,
            // P-G3 — the real product behind this option (null = classic
            // label-only add-on). Inlined when linkedProduct is loaded.
            'linked_product_id' => $this->linked_product_id !== null ? (int) $this->linked_product_id : null,
            'linked_product' => $this->whenLoaded('linkedProduct', fn (): ?array => $this->linkedProduct === null ? null : [
                'uuid' => $this->linkedProduct->uuid,
                'name' => $this->linkedProduct->name,
                'stock_mode' => $this->linkedProduct->stock_mode,
            ]),
            // PD3b — the option's stock-usage lines (ingredient lines in
            // the ingredient's BASE unit; product lines in pieces).
            'consumption' => $this->whenLoaded('consumptionLines', fn (): array => $this->consumptionLines->map(static fn ($line): array => [
                'type' => $line->ingredient_id !== null ? 'ingredient' : 'product',
                'direction' => $line->direction,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit,
                'ingredient' => $line->ingredient === null ? null : [
                    'uuid' => $line->ingredient->uuid,
                    'name' => $line->ingredient->name,
                    'unit' => $line->ingredient->unit?->value,
                ],
                'product' => $line->componentProduct === null ? null : [
                    'uuid' => $line->componentProduct->uuid,
                    'name' => $line->componentProduct->name,
                    'stock_mode' => $line->componentProduct->stock_mode,
                    'is_internal' => (bool) $line->componentProduct->is_internal,
                ],
            ])->values()->all()),
            'display_order' => $this->display_order,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

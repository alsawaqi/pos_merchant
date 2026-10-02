<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\Ingredient;
use App\Support\Recipes\PrepGraph;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'unit' => $this->unit?->value,
            // Phase A — piece model (Additions §2.3).
            'piece_unit_label' => $this->piece_unit_label,
            'piece_unit_label_ar' => $this->piece_unit_label_ar,
            'units_per_piece' => $this->units_per_piece !== null ? (string) $this->units_per_piece : null,
            'allow_fractional_pieces' => (bool) $this->allow_fractional_pieces,
            // Cost (up to 6dp) + threshold (up to 4dp) as strings (ScaledDecimal cast).
            // LAUNCH-P3 P3-4 — a prep item stores no cost: it costs what its
            // recipe costs per base unit (PrepGraph), so the recipe editors'
            // live cost works the same for both kinds.
            'default_unit_cost' => $this->is_prep
                ? PrepGraph::forCompany((int) $this->company_id)->unitCost((int) $this->id)
                : (string) $this->default_unit_cost,
            'is_prep' => (bool) $this->is_prep,
            'prep_yield_quantity' => $this->is_prep ? (string) $this->prep_yield_quantity : null,
            'min_stock_threshold' => $this->min_stock_threshold !== null
                ? (string) $this->min_stock_threshold
                : null,
            'primary_supplier_id' => $this->primary_supplier_id,
            'primary_supplier' => $this->whenLoaded('primarySupplier', function () {
                return $this->primarySupplier === null ? null : [
                    'id' => $this->primarySupplier->id,
                    'uuid' => $this->primarySupplier->uuid,
                    'name' => $this->primarySupplier->name,
                ];
            }),
            'status' => $this->status,
            // v2 #13 — alternate units (when loaded), so the ingredient form can
            // render its unit list without a second round-trip.
            'alt_units' => IngredientAltUnitResource::collection($this->whenLoaded('altUnits')),
            // PD4 — same-family metric units the system provides automatically
            // (base kg -> g, base l -> ml...). Derived from the base unit, so it
            // ships unconditionally; the dropdowns merge these with alt_units and
            // the converter resolves the same names. Empty for count units.
            'auto_units' => collect($this->unit?->metricSiblings() ?? [])
                ->map(static fn (float $factor, string $name): array => [
                    'name' => $name,
                    'factor' => rtrim(rtrim(number_format($factor, 4, '.', ''), '0'), '.'),
                ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

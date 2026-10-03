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
            // LAUNCH item kind — weighed / liquid / counted, read from the
            // stored unit (an older kg ingredient is Weighed, stored in kg).
            'kind' => $this->unit?->kind(),
            // A2 — whether the kind can no longer change (only when the
            // endpoint worked it out; see IngredientUnitLock).
            'unit_locked' => $this->when($this->resource->unitLocked !== null, fn (): bool => (bool) $this->resource->unitLocked),
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
            // LAUNCH item kind G1 — and the US units (lb / oz, gal / fl oz),
            // with their exact factors (0.0295735295625 l per fl oz).
            'auto_units' => collect($this->unit?->convertibleUnitFactors() ?? [])
                ->map(static fn (string $factor, string $name): array => [
                    'name' => $name,
                    'factor' => $factor,
                ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

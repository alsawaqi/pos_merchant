<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\Ingredient;
use App\Support\Catalogue\AllergenSync;
use App\Support\Inventory\ContainerPresenter;
use App\Support\Inventory\Containers;
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
            // LAUNCH review add-on (A1) — "No cost yet": the cost comes only
            // from purchases; 0 means no priced purchase yet. A prep item has
            // a cost once every raw ingredient it uses has one.
            'has_cost' => $this->is_prep
                ? PrepGraph::forCompany((int) $this->company_id)->costComplete((int) $this->id)
                : $this->resource->hasCost(),
            // A4 — the supplier's code, or a generated ING-0001.
            'sku' => $this->sku,
            // A3 — the container tills count in (its mirror is piece_* below).
            'count_container_uuid' => $this->count_container_id !== null
                ? Containers::of($this->resource)->first(fn ($c): bool => (int) $c->id === (int) $this->count_container_id)?->uuid
                : null,
            // A5 — barcodes on the item itself (container barcodes ride each
            // container in alt_units).
            'barcodes' => $this->whenLoaded('barcodes', fn (): array => $this->barcodes
                ->filter(static fn ($b): bool => $b->container_id === null)
                ->map(static fn ($b): array => $b->summary())
                ->values()
                ->all()),
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
            // LAUNCH costs & allergens add-on — the allergens ticked on the
            // item, and (a prep item) with what its recipe brings.
            'allergens' => AllergenSync::graph((int) $this->company_id)->ownIngredient((int) $this->id),
            'allergens_all' => AllergenSync::graph((int) $this->company_id)->ingredient((int) $this->id),
            // v2 #13 — alternate units (when loaded), so the ingredient form can
            // render its unit list without a second round-trip.
            // LAUNCH review add-on (A2) — these are the item's CONTAINERS: each
            // with its token, display name (EN/AR), what it holds, the
            // count-container marker and its barcodes ({@see ContainerPresenter}).
            'alt_units' => $this->whenLoaded('altUnits', fn (): array => ContainerPresenter::all($this->resource)),
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

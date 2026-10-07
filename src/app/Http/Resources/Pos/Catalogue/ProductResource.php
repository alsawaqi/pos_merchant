<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Enums\MerchantPermission;
use App\Http\Controllers\Pos\AllergensController;
use App\Http\Resources\Pos\DeliveryProviders\ProductDeliveryPriceResource;
use App\Models\Product;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\OrderTypes;
use App\Support\Costs\FoodCost;
use App\Support\Recipes\RecipeCostComplete;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see Product}. Money columns are
 * strings (Laravel decimal cast) — the frontend treats
 * them as exact-precision strings, never parses to float.
 *
 * cost_price is INTENTIONALLY exposed in the merchant
 * portal payload (managers + inventory specialists need to
 * see margins). The future POS-device payload will omit it
 * — cashiers shouldn't see how much you paid for the cup.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return $this->category === null ? null : [
                    'id' => $this->category->id,
                    'uuid' => $this->category->uuid,
                    'name' => $this->category->name,
                ];
            }),
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'description' => $this->description,
            // LAUNCH-P4 L5 — the Arabic description.
            'description_ar' => $this->description_ar,
            'image_url' => $this->image_url,
            // LAUNCH-P4 — 'standard' | 'combo' (B2); the channels (B3): in
            // store, delivery, and the QR menu (show_on_customer_tablet); the
            // branch rule (H6): 'all' | 'selected'.
            'product_type' => (string) ($this->product_type ?? 'standard'),
            'sold_in_store' => (bool) ($this->sold_in_store ?? true),
            'sold_on_delivery' => (bool) ($this->sold_on_delivery ?? true),
            'branch_scope' => (string) ($this->branch_scope ?? 'all'),
            // LAUNCH-P4 B4 — the branches (of the user's scope) where it is
            // switched sold out, when the controller eager-loaded them.
            'sold_out_branch_ids' => $this->whenLoaded('soldOutRows', fn (): array => $this->soldOutRows
                ->pluck('branch_id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all()),
            'base_price' => (string) $this->base_price,
            // Phase 4.9 — per-product delivery override. NULL
            // means "no markup, use base_price for delivery too".
            // The POS / sync endpoint uses Product::priceFor()
            // to resolve the right price at order time.
            'delivery_price' => $this->delivery_price !== null ? (string) $this->delivery_price : null,
            // Phase 7 — stock mode: unit (finished, piece-counted) | ingredient
            // (recipe-driven) | untracked (sold freely).
            'stock_mode' => $this->stock_mode,
            // Phase D2 — unit-mode LOW STOCK badge threshold (decimal
            // string). NULL = no badge.
            'low_stock_threshold' => $this->low_stock_threshold !== null ? (string) $this->low_stock_threshold : null,
            // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
            'shelf_life_days' => $this->shelf_life_days !== null ? (int) $this->shelf_life_days : null,
            'cost_price' => $this->cost_price !== null ? (string) $this->cost_price : null,
            // Effective tax: column when set, NULL means
            // "inherit company default". Frontend resolves
            // the effective rate when needed.
            'tax_rate' => $this->tax_rate !== null ? (string) $this->tax_rate : null,
            // Phase D2 — §5.5.3 tax-inclusive flag. Display-only for now:
            // order totals still add company taxes on top (exclusive).
            'tax_inclusive' => (bool) $this->tax_inclusive,
            // Phase D2 — §5.5.3 customer tablet visibility. Consumed by the
            // future tablet menu app; the staff POS ignores it.
            'show_on_customer_tablet' => (bool) $this->show_on_customer_tablet,
            // G1 — menu time-window. Raw 'HH:MM:SS' strings; both NULL =
            // always available; start > end wraps midnight (the
            // pos_discounts convention, evaluated on-device).
            'available_from' => $this->available_from,
            'available_until' => $this->available_until,
            // LAUNCH review add-on — limited-time dates ('YYYY-MM-DD', both
            // inclusive, Asia/Muscat; null = no bound) and the cooking time
            // in minutes (null = not set).
            'on_sale_from' => $this->on_sale_from,
            'on_sale_until' => $this->on_sale_until,
            'cooking_minutes' => $this->cooking_minutes !== null ? (int) $this->cooking_minutes : null,
            'display_order' => $this->display_order,
            'status' => $this->status?->value,
            // Phase 4.9 — attached add-on groups (product-specific,
            // not global) inlined when the controller eager-loads
            // them. Global groups are NOT included here — the
            // resolver handles them at POS-render time.
            'addon_groups' => AddOnGroupResource::collection($this->whenLoaded('addOnGroups')),
            // Phase 5b — recipe metadata.
            //   has_recipe: cheap boolean for the list-view
            //               badge column.
            //   theoretical_cost: CURRENT recipe cost (sum of
            //               quantity × ingredient.default_unit_cost
            //               at current prices). Useful for the
            //               margin display in the product modal.
            //               Phase 8 sale orders snapshot the
            //               historical cost — this is the live
            //               number, not historical.
            //   recipe_lines: inlined when the controller eager-
            //               loaded recipeLines + ingredient. Edit
            //               modal pre-populates from this.
            'has_recipe' => $this->hasRecipe(),
            'theoretical_cost' => $this->theoreticalCost(),
            // LAUNCH review add-on (A1) — false while a recipe ingredient has "No cost yet".
            'theoretical_cost_complete' => RecipeCostComplete::forProduct($this->resource),
            // LAUNCH costs & allergens add-on — the dish's own target food
            // cost % (null = the company target) and, for a user who may see
            // costs (reports.view), its food cost: {status, cost_baisas,
            // net_price_baisas, food_cost_pct, target_pct, target_source,
            // over_target, over_by_pct, cost_complete} ({@see FoodCost}).
            'target_food_cost_percent' => $this->target_food_cost_percent !== null ? (string) $this->target_food_cost_percent : null,
            'food_cost' => $request->user()?->can(MerchantPermission::ReportsView->value)
                ? FoodCost::forCompany((int) $this->company_id)->product((int) $this->id)
                : null,
            // The allergens: contains / may contain, what was worked out
            // (locked) and the ticks set by hand ({@see AllergensController::present()}).
            'allergens' => AllergensController::present((int) $this->company_id, (int) $this->id),
            'recipe_lines' => ProductRecipeResource::collection($this->whenLoaded('recipeLines')),
            // P-G2 — internal item (cups/lids): never on the POS menu or
            // tablet; full stock participation.
            'is_internal' => (bool) $this->is_internal,
            // P-G2 — physical-item components per unit sold, inlined when
            // the controller eager-loaded components.component (the edit
            // modal pre-populates from this).
            'component_lines' => $this->whenLoaded('components', fn (): array => $this->components->map(fn ($line): array => [
                'component_uuid' => (string) $line->component?->uuid,
                'component_name' => $line->component?->name,
                'quantity' => (string) $line->quantity,
                // LAUNCH packaging add-on — "Used for" ticks (15 = every order type).
                'order_types' => OrderTypes::read($line->order_types),
            ])->values()->all()),
            // Per-branch availability + unit stock (which branches sell this
            // product + how many units each holds). Empty/absent = available
            // everywhere. Inlined when the controller eager-loads branchProducts.
            'branches' => $this->whenLoaded('branchProducts', fn (): array => $this->branchProducts->map(fn ($bp): array => [
                'branch_id' => (int) $bp->branch_id,
                'is_available' => (bool) $bp->is_available,
                'stock_qty' => $bp->stock_qty !== null ? (float) $bp->stock_qty : null,
            ])->values()->all()),
            // Phase 6c — per-provider price overrides inlined
            // when the controller eager-loaded `deliveryPrices`
            // + the provider relation. Product edit modal uses
            // this to pre-populate the provider-price grid.
            'delivery_provider_prices' => ProductDeliveryPriceResource::collection($this->whenLoaded('deliveryPrices')),
            // LAUNCH combo add-on — a combo's lines (included items with their
            // upgrades, choices with their overrides), inlined when the
            // controller loaded comboLines ({@see ComboLinesInput::present()}).
            'combo' => $this->when($this->resource->relationLoaded('comboLines'), fn (): array => [
                'lines' => ComboLinesInput::present(['combo_product_id' => (int) $this->id]),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

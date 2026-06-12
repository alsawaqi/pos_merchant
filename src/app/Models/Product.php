<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An orderable item the merchant sells.
 *
 * Money handling: base_price + cost_price are decimal(12,3)
 * for OMR baisas precision. Cast to `decimal:3` on the model
 * so reads always return a string with 3 decimals (Laravel's
 * decimal cast keeps precision intact across PHP's float
 * peril). Frontend re-parses to BigInt-like math when
 * tallying line items.
 *
 * tax_rate: NULL means "use company default". A non-NULL
 * value overrides, including 0.00 (zero-rated). See the
 * Phase 6 tax model discussion in the migration comment.
 *
 * Schema owned by pos_admin
 * (2026_05_27_040100_create_pos_products_table).
 */
#[Fillable([
    'uuid',
    'company_id',
    'category_id',
    'sku',
    'barcode',
    'name',
    'name_ar',
    'description',
    'image_url',
    'base_price',
    // Phase 4.9 — per-product delivery override. NULL = use
    // base_price for delivery orders too.
    'delivery_price',
    // Phase 7 — stock mode: unit | ingredient | untracked.
    'stock_mode',
    // Phase D2 — unit-mode LOW STOCK badge threshold (NULL = no badge).
    'low_stock_threshold',
    // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
    'shelf_life_days',
    'cost_price',
    'tax_rate',
    // Phase D2 — §5.5.3 tax-inclusive flag (display-only for now).
    'tax_inclusive',
    'display_order',
    'status',
    // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no".
    'show_on_customer_tablet',
    // G1 — menu time-window. 'HH:MM:SS' strings, both NULL = always
    // available, start > end wraps midnight (pos_discounts convention).
    'available_from',
    'available_until',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_products';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:3',
            // Phase 4.9 — same shape as base_price so OMR-baisa
            // precision stays consistent through the channel-aware
            // price resolver below.
            'delivery_price' => 'decimal:3',
            // Phase D2 — same precision as the branch stock_qty it is
            // compared against for the LOW STOCK badge.
            'low_stock_threshold' => 'decimal:3',
            'cost_price' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'display_order' => 'integer',
            'status' => ProductStatus::class,
            'show_on_customer_tablet' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    /**
     * Phase 4.9 — Product-specific add-on groups attached via
     * the pivot. Does NOT include global groups (is_global=true)
     * — use {@see resolvedAddOnGroups()} when you want both
     * sources unioned for POS rendering.
     *
     * @return BelongsToMany<AddOnGroup, $this>
     */
    public function addOnGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            AddOnGroup::class,
            'pos_addon_group_products',
            'product_id',
            'add_on_group_id',
        )->withPivot('display_order')->withTimestamps();
    }

    /**
     * Resolves every add-on group that should appear for this
     * product on the POS — global groups for the same company
     * UNION explicit pivot attachments. Returns an eager-
     * loaded collection of AddOnGroup with their addOns
     * relation hydrated.
     *
     * Used by Phase 8's device config bundle endpoint so the
     * POS doesn't have to know about the global-vs-pivot
     * distinction.
     *
     * @return EloquentCollection<int, AddOnGroup>
     */
    public function resolvedAddOnGroups(): EloquentCollection
    {
        return AddOnGroup::query()
            ->where('company_id', $this->company_id)
            ->where('status', 'active')
            ->where(function (Builder $q): void {
                $q->where('is_global', true)
                    ->orWhereIn(
                        'id',
                        $this->addOnGroups()->select('pos_addon_groups.id')->getQuery(),
                    );
            })
            ->with(['addOns' => function ($q): void {
                $q->where('status', 'active');
            }])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Phase 4.9 — channel-aware price resolver. Returns the
     * correct base price for an order context.
     *
     *   delivery order + delivery_price set → delivery_price
     *   anything else                       → base_price
     *
     * Returns a string (decimal cast preserves precision —
     * NEVER cast to float for money math).
     */
    public function priceFor(string $orderType): string
    {
        if ($orderType === 'delivery' && $this->delivery_price !== null) {
            return (string) $this->delivery_price;
        }

        return (string) $this->base_price;
    }

    // ===================== Phase 6c — Provider pricing =====================

    /**
     * Per-provider price overrides for this product.
     *
     * @return HasMany<ProductDeliveryPrice, $this>
     */
    public function deliveryPrices(): HasMany
    {
        return $this->hasMany(ProductDeliveryPrice::class);
    }

    /**
     * Phase 6c — resolve the price for a specific delivery
     * provider, with the 3-step fallback chain:
     *
     *   1. Override row in pos_product_delivery_prices for the
     *      (product, provider) pair → use it
     *   2. Else this->delivery_price (Phase 4.9, in-house
     *      delivery default) → use it
     *   3. Else this->base_price (regular menu price)
     *
     * Reads the loaded `deliveryPrices` relation if available
     * (no extra query when callers eager-loaded); falls back
     * to a one-row query otherwise.
     *
     * Returns a decimal-3 string. NEVER float — OMR baisas
     * precision matters end-to-end.
     */
    public function resolvedDeliveryPriceFor(int $providerId): string
    {
        $override = null;
        if ($this->relationLoaded('deliveryPrices')) {
            /** @var ProductDeliveryPrice|null $override */
            $override = $this->deliveryPrices->firstWhere('delivery_provider_id', $providerId);
        } else {
            $override = $this->deliveryPrices()
                ->where('delivery_provider_id', $providerId)
                ->first();
        }

        if ($override !== null) {
            return (string) $override->price;
        }

        if ($this->delivery_price !== null) {
            return (string) $this->delivery_price;
        }

        return (string) $this->base_price;
    }

    // ===================== Phase 5b — Recipes =====================

    /**
     * Current recipe lines (one per ingredient). Empty = "no
     * recipe / pre-made goods, no inventory deduction on sale".
     *
     * @return HasMany<ProductRecipe, $this>
     */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(ProductRecipe::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Per-branch availability + unit stock. No rows = available at every
     * branch by default (the device-config backward-compatible default).
     *
     * @return HasMany<BranchProduct, $this>
     */
    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /**
     * Append-only pre-edit recipe snapshots. Newest first —
     * the UI shows them as a history timeline in the product
     * edit modal.
     *
     * @return HasMany<ProductRecipeVersion, $this>
     */
    public function recipeVersions(): HasMany
    {
        return $this->hasMany(ProductRecipeVersion::class)
            ->orderByDesc('edited_at')
            ->orderByDesc('id');
    }

    /**
     * Phase 5b — true when the product has at least one recipe
     * line. Used by the Phase 8 order pipeline to decide
     * whether to write sale_consumption stock movements (no
     * recipe = nothing to deduct). The Phase 5b UI shows a
     * "has recipe" badge on products that return true here.
     *
     * Reads the loaded relation if available (no extra query
     * when callers eager-loaded recipeLines); falls back to a
     * count query otherwise.
     */
    public function hasRecipe(): bool
    {
        if ($this->relationLoaded('recipeLines')) {
            return $this->recipeLines->isNotEmpty();
        }
        return $this->recipeLines()->exists();
    }

    /**
     * Phase 5b — theoretical recipe cost at the CURRENT
     * ingredient default_unit_cost. Σ over all recipe lines of
     * (quantity × ingredient.default_unit_cost).
     *
     * Returns "0.000" for products with no recipe.
     *
     * IMPORTANT: this is the *current* cost — not the historical
     * cost at order time. Phase 8 order lines snapshot the
     * recipe + cost so historical COGS stays accurate. This
     * helper is for the merchant-portal cost display and
     * margin calculation only.
     *
     * Returns a string with 3 decimals to keep precision
     * parity with base_price / cost_price.
     */
    public function theoreticalCost(): string
    {
        $lines = $this->relationLoaded('recipeLines')
            ? $this->recipeLines
            : $this->recipeLines()->with('ingredient')->get();

        $total = 0.0;
        foreach ($lines as $line) {
            $cost = (float) ($line->ingredient?->default_unit_cost ?? 0);
            $qty = (float) $line->quantity;
            $total += $qty * $cost;
        }

        return number_format($total, 3, '.', '');
    }
}

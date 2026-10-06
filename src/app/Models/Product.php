<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Catalogue\OrderTypes;
use App\Support\Recipes\PrepGraph;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
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
    // P-G2 — internal items (cups/lids): never on the POS menu or the
    // customer tablet, full stock participation.
    'is_internal',
    // PD3a — physical-item kind: 'packaging' (used with food) |
    // 'general' (branch use) | NULL (non-internal / legacy=packaging).
    'internal_purpose',
    // G1 — menu time-window. 'HH:MM:SS' strings, both NULL = always
    // available, start > end wraps midnight (pos_discounts convention).
    'available_from',
    'available_until',
    // LAUNCH-P4 data contract — 'standard' | 'combo'; the in-store and
    // delivery channel switches; the branch rule ('all' | 'selected');
    // the Arabic description.
    'product_type',
    'sold_in_store',
    'sold_on_delivery',
    'branch_scope',
    'description_ar',
    // LAUNCH review add-on — limited-time dates ('YYYY-MM-DD', inclusive,
    // Asia/Muscat; NULL = no bound) and the cooking time in minutes
    // (0..240, NULL = not set).
    'on_sale_from',
    'on_sale_until',
    'cooking_minutes',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

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
            'is_internal' => 'boolean',
            'sold_in_store' => 'boolean',
            'sold_on_delivery' => 'boolean',
            // LAUNCH review add-on. The two dates stay plain 'YYYY-MM-DD'
            // strings (no date cast: they are calendar days, not instants).
            'cooking_minutes' => 'integer',
        ];
    }

    /** LAUNCH-P4 — product_type values. */
    public const TYPE_STANDARD = 'standard';

    public const TYPE_COMBO = 'combo';

    /** LAUNCH-P4 — branch_scope values. */
    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    public function isCombo(): bool
    {
        return $this->product_type === self::TYPE_COMBO;
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

        // LAUNCH-P4 — a provider row with a NULL price only carries the
        // listed flag: the price falls through to the next step.
        if ($override !== null && $override->price !== null) {
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
     * LAUNCH-P4 — a combo's choice slots (empty for a standard product).
     *
     * @return HasMany<ComboSlot, $this>
     */
    public function comboSlots(): HasMany
    {
        return $this->hasMany(ComboSlot::class, 'combo_product_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * LAUNCH-P4 — the branches where this product is switched to sold out.
     *
     * @return HasMany<ProductSoldOut, $this>
     */
    public function soldOutRows(): HasMany
    {
        return $this->hasMany(ProductSoldOut::class);
    }

    /**
     * P-G2 — the physical items this product consumes per unit sold
     * (coffee = 1 x cup 12oz + 1 x lid). Components are unit-mode
     * products; empty = no component consumption.
     *
     * @return HasMany<ProductComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class)->orderBy('id');
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
    public function theoreticalCost(bool $perUnitPrecision = false, ?int $orderTypeBit = null): string
    {
        $lines = $this->relationLoaded('recipeLines')
            ? $this->recipeLines
            : $this->recipeLines()->with('ingredient')->get();

        // LAUNCH-P3 P3-4 — a line using a PREP ITEM costs what the prep
        // recipe costs (PrepGraph: Σ component × cost ÷ yield, recursively);
        // exact arithmetic, rounded once at the end.
        // LAUNCH packaging add-on — with an order type bit, only the lines
        // ticked "Used for" that type (null = every line).
        $total = BigRational::zero();
        $prepLines = [];
        foreach ($lines as $line) {
            if (! OrderTypes::includes($line->order_types ?? null, $orderTypeBit)) {
                continue;
            }
            if ($line->ingredient?->is_prep) {
                // Lines of one prep item with different ticks add up.
                $prepLines[(int) $line->ingredient_id] = (string) BigDecimal::of($prepLines[(int) $line->ingredient_id] ?? '0')->plus((string) $line->quantity);

                continue;
            }
            $total = $total->plus(
                BigRational::of((string) $line->quantity)->multipliedBy((string) ($line->ingredient?->default_unit_cost ?? '0')),
            );
        }
        if ($prepLines !== []) {
            $total = $total->plus(PrepGraph::forCompany((int) $this->company_id)->linesCostExact($prepLines));
        }

        // LAUNCH-P2 — a frozen per-piece cost (product waste) keeps 6
        // decimals so it is never rounded before it is multiplied; the
        // displayed cost stays at OMR baisa.
        return $perUnitPrecision
            ? (string) StockDecimal::unitCost((string) $total->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP))
            : (string) $total->toScale(3, RoundingMode::HALF_UP);
    }
}

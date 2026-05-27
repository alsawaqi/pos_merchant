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
    'cost_price',
    'tax_rate',
    'display_order',
    'status',
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
            'cost_price' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'display_order' => 'integer',
            'status' => ProductStatus::class,
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
}

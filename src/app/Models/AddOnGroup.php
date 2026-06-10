<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddOnSelectionMode;
use Database\Factories\AddOnGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 4.9 — a named bundle of add-ons ("Milk Choice",
 * "Sugar Level", "Extras").
 *
 * Two kinds:
 *   - Global (is_global=true): applies to every product in the
 *     company. Examples: "Sugar Level" or "Service Type" that
 *     make sense on every menu item.
 *   - Product-specific (is_global=false + pivot rows): attached
 *     explicitly to one or more products via the
 *     pos_addon_group_products pivot.
 *
 * Schema owned by pos_admin's
 * 2026_05_28_010000_create_pos_addon_groups_and_addons.
 */
#[Fillable([
    'uuid',
    'company_id',
    'owner_product_id',
    'name',
    'name_ar',
    'selection_mode',
    'min_selections',
    'max_selections',
    'is_global',
    'display_order',
    'status',
])]
class AddOnGroup extends Model
{
    /** @use HasFactory<AddOnGroupFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_addon_groups';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_mode' => AddOnSelectionMode::class,
            // Phase B — selection constraints (NULL = unbounded;
            // min >= 1 makes the group REQUIRED at the POS).
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'is_global' => 'boolean',
            'display_order' => 'integer',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<AddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(AddOn::class)
            ->orderBy('display_order')
            ->orderBy('name');
    }

    /**
     * Products this group is explicitly attached to. Global
     * groups apply to every product without pivot rows — the
     * resolver in {@see Product::resolvedAddOnGroups()} unions
     * both sources.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'pos_addon_group_products',
            'add_on_group_id',
            'product_id',
        )->withPivot('display_order')->withTimestamps();
    }

    /**
     * Phase B — categories this group is bound to ("attach a group to
     * a category"; the device unions category + product bindings, so
     * the more specific binding wins by construction).
     *
     * @return BelongsToMany<ProductCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCategory::class,
            'pos_addon_group_categories',
            'add_on_group_id',
            'category_id',
        );
    }
}

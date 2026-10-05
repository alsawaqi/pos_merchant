<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddOnSelectionMode;
use App\Models\Concerns\BelongsToCompany;
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
    // LAUNCH review add-on — 'extras' | 'remove' | 'instructions'.
    'kind',
])]
class AddOnGroup extends Model
{
    /** @use HasFactory<AddOnGroupFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $table = 'pos_addon_groups';

    /**
     * LAUNCH review add-on — the group kinds (pos_addon_groups.kind):
     *   - extras: today's groups (priced options, stock, linked products);
     *   - remove: ONE per product, owned by it and managed only from its
     *     recipe step ("Can be removed"); each option names the recipe
     *     ingredient it leaves out (removes_ingredient_id), price 0;
     *   - instructions: a tap list ("Well done", "Less spicy"), price 0, no
     *     stock, no linked product, never required, several may be picked.
     */
    public const KIND_EXTRAS = 'extras';

    public const KIND_REMOVE = 'remove';

    public const KIND_INSTRUCTIONS = 'instructions';

    /** The kind, with a row saved before the column existed read as extras. */
    public function kindValue(): string
    {
        return (string) ($this->kind ?? self::KIND_EXTRAS);
    }

    public function isRemoveGroup(): bool
    {
        return $this->kindValue() === self::KIND_REMOVE;
    }

    public function isInstructionsGroup(): bool
    {
        return $this->kindValue() === self::KIND_INSTRUCTIONS;
    }

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
     * LAUNCH-P4 M4 — is this group name already taken where the DB
     * indexes look?
     *
     *   - A product's own group ($ownerProductId set): unique per owner
     *     product (pos_addon_groups_owner_name_unique), so two products
     *     can each own a "Size" group.
     *   - A shared group ($ownerProductId null): unique per company among
     *     shared groups only (pos_addon_groups_company_shared_name_unique).
     *
     * Soft-deleted rows still hold their name (the indexes have no
     * deleted_at filter), so this counts them too — the caller then
     * returns a clean 422 instead of tripping the index mid-insert.
     */
    public static function nameTaken(int $companyId, ?int $ownerProductId, string $name, ?int $exceptId = null): bool
    {
        $query = static::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('name', $name);

        if ($ownerProductId !== null) {
            $query->where('owner_product_id', $ownerProductId);
        } else {
            $query->whereNull('owner_product_id');
        }

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
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

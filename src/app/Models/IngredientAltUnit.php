<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Inventory\ContainerToken;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * v2 #13 — an ingredient's ALTERNATE unit (pos_ingredient_units). The
 * ingredient's base unit lives on pos_ingredients.unit; this row defines a unit
 * a merchant can enter quantities in, with `factor` = base units per ONE of it
 * (e.g. base g, name "kg", factor 1000 → entering 2 kg stores 2000 g).
 *
 * LAUNCH review add-on (A2) — these rows are the item's CONTAINERS ("How do
 * you buy it?"): several per item, the same word allowed with different sizes
 * ("bottle 1.5 l" and "bottle 500 ml"), and nested — a crate may hold
 * contains_quantity × another container of the same item ("crate holds 12 ×
 * bottle 1 l"). `factor` keeps its meaning: base units in ONE, nesting
 * included (a crate of 12 × 1000 ml = 12000). A container is named on the
 * wire by its token ({@see ContainerToken}), never only by its name.
 *
 * Storage everywhere stays in base; conversion happens only at the entry
 * boundary (see {@see \App\Actions\Pos\Inventory\IngredientUnitConverter}).
 * Schema owned by pos_admin's 2026_06_30 migration (+ 2026_10_06_100001).
 */
#[Fillable([
    'uuid',
    'company_id',
    'ingredient_id',
    'name',
    'name_ar',
    'factor',
    'sort_order',
    'contains_unit_id',
    'contains_quantity',
])]
class IngredientAltUnit extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'pos_ingredient_units';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factor' => 'decimal:4',
            'sort_order' => 'integer',
            'contains_quantity' => 'decimal:4',
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
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * The container one of this holds N of ("crate holds 12 × bottle").
     *
     * @return BelongsTo<self, $this>
     */
    public function containsUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contains_unit_id')->withTrashed();
    }

    /**
     * LAUNCH review add-on (A5) — the barcodes printed on this container.
     *
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'container_id');
    }

    /** A LEAF container holds an amount of the item, not other containers. */
    public function isLeaf(): bool
    {
        return $this->contains_unit_id === null;
    }

    /** The wire token naming this container ("#" + its uuid, compact). */
    public function token(): string
    {
        return ContainerToken::encode((string) $this->uuid);
    }
}

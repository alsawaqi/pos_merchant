<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * v2 #13 — an ingredient's ALTERNATE unit (pos_ingredient_units). The
 * ingredient's base unit lives on pos_ingredients.unit; this row defines a unit
 * a merchant can enter quantities in, with `factor` = base units per ONE of it
 * (e.g. base g, name "kg", factor 1000 → entering 2 kg stores 2000 g).
 *
 * Storage everywhere stays in base; conversion happens only at the entry
 * boundary (see {@see \App\Actions\Pos\Inventory\IngredientUnitConverter}).
 * Schema owned by pos_admin's 2026_06_30 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'ingredient_id',
    'name',
    'name_ar',
    'factor',
    'sort_order',
])]
class IngredientAltUnit extends Model
{
    use SoftDeletes;

    protected $table = 'pos_ingredient_units';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factor' => 'decimal:4',
            'sort_order' => 'integer',
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
}

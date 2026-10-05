<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * LAUNCH review add-on (A5, D3, F) — a barcode the scan box recognises.
 *
 * Exactly one of ingredient_id / product_id is set: an ingredient barcode may
 * name the container it is printed on (several barcodes per container — other
 * brands of the same size); a physical-item barcode may name its pack (NULL =
 * one piece). Unique per company among live rows, and checked against
 * pos_products.barcode in both directions ({@see \App\Support\Inventory\ItemCodes}).
 * Kept as a trimmed string: leading zeros stay.
 *
 * Schema owned by pos_admin (2026_10_06_100006).
 */
#[Fillable([
    'uuid',
    'company_id',
    'barcode',
    'ingredient_id',
    'container_id',
    'product_id',
    'pack_id',
    'label',
    'created_by_user_id',
])]
class ItemBarcode extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'pos_item_barcodes';

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
     * @return BelongsTo<IngredientAltUnit, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(IngredientAltUnit::class, 'container_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(ProductPack::class, 'pack_id');
    }

    /**
     * @return array{uuid: string, barcode: string, label: ?string}
     */
    public function summary(): array
    {
        return [
            'uuid' => (string) $this->uuid,
            'barcode' => (string) $this->barcode,
            'label' => $this->label,
        ];
    }
}

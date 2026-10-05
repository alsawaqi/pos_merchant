<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * LAUNCH review add-on (D3) — a physical item's container ("box holds 50
 * cups", "carton holds 4 × box 50"). `pieces` is the number of the item's
 * pieces in ONE pack, nesting included (a whole number ≥ 2); a nested pack
 * also says which pack it holds and how many. Used by Purchases and the scan
 * box; physical items keep no breakdown by pack.
 *
 * Schema owned by pos_admin (2026_10_06_100005).
 */
#[Fillable([
    'uuid',
    'company_id',
    'product_id',
    'name',
    'name_ar',
    'pieces',
    'contains_pack_id',
    'contains_quantity',
    'sort_order',
])]
class ProductPack extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'pos_product_packs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pieces' => 'decimal:4',
            'contains_quantity' => 'decimal:4',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function containsPack(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contains_pack_id')->withTrashed();
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'pack_id');
    }
}

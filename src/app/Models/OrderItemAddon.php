<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemAddonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7a — per-line add-on selection (blueprint §10.8).
 *
 * Schema owned by pos_admin's 2026_06_04_010200 migration.
 *
 * price_delta_snapshot is SIGNED — a "no milk" modifier
 * configured as -0.500 OMR is legitimate.
 *
 * ingredient_snapshot_json freezes the add-on's ingredient
 * tie at write time. Phase 8 stock-deduction pipeline reads
 * this so a later add-on recipe edit doesn't retroactively
 * shift stock movements.
 */
#[Fillable([
    'order_item_id',
    'add_on_id',
    'add_on_name_snapshot',
    'price_delta_snapshot',
    'ingredient_snapshot_json',
])]
class OrderItemAddon extends Model
{
    /** @use HasFactory<OrderItemAddonFactory> */
    use HasFactory;

    protected $table = 'pos_order_item_addons';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_snapshot' => 'decimal:3',
            'ingredient_snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<AddOn, $this>
     */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }
}

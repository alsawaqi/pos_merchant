<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderItemStatus;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7a — single line on an order (blueprint §10.8).
 *
 * SNAPSHOT COLUMNS:
 *   product_name_snapshot   product's display name at write time
 *   unit_price_snapshot     per-unit charge at write time
 *   line_discount            discount applied to THIS line
 *   line_total               qty × unit_price - line_discount
 *   recipe_snapshot_json     product's recipe at write time;
 *                            Phase 8 reads this for stock deduction
 *
 * Snapshots mean a later catalogue / recipe edit doesn't
 * retroactively shift historical totals or stock movements.
 *
 * Schema owned by pos_admin's 2026_06_04_010100 migration.
 */
#[Fillable([
    'order_id',
    'product_id',
    'product_name_snapshot',
    'qty',
    'unit_price_snapshot',
    'line_discount',
    'line_total',
    'recipe_snapshot_json',
    'status',
    'notes',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $table = 'pos_order_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:3',
            'line_discount' => 'decimal:3',
            'line_total' => 'decimal:3',
            // Postgres jsonb / sqlite text — Laravel auto-decodes
            // to array on read, encodes on write.
            'recipe_snapshot_json' => 'array',
            'status' => OrderItemStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Per-line add-on selections. "Latte with extra shot + oat"
     * = two addon rows attached to this line.
     *
     * @return HasMany<OrderItemAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(OrderItemAddon::class)->orderBy('id');
    }
}

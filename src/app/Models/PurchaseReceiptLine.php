<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PD6 — one line of a Goods Received Note: an item bought, its quantity + cost,
 * and a snapshot of where it was distributed.
 *
 * item_type is 'ingredient' or 'product' (a product covers a ready/bought-in
 * sellable AND a physical item — is_internal on the product decided the booked
 * expense category, snapshotted here in expense_category). quantity is in the
 * item's base unit; line_cost booked the categorized purchase expense (linked
 * via expense_id; NULL when the line was free). allocations_json freezes the
 * branch split.
 *
 * Schema owned by pos_admin's 2026_07_24_010000 migration.
 */
#[Fillable([
    'purchase_receipt_id',
    'item_type',
    'ingredient_id',
    'product_id',
    'item_name',
    'quantity',
    'unit',
    'line_cost',
    'expense_category',
    'allocations_json',
    'expense_id',
    'display_order',
])]
class PurchaseReceiptLine extends Model
{
    protected $table = 'pos_purchase_receipt_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'line_cost' => 'decimal:3',
            'allocations_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

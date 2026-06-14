<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PD6 — a named receipt-level extra charge (delivery, customs, handling…). Each
 * books its OWN pos_expenses row under its chosen category (default 'delivery'),
 * linked via expense_id. Kept SEPARATE from the item line costs so the receipt
 * shows item cost vs. logistics distinctly.
 *
 * Schema owned by pos_admin's 2026_07_24_010000 migration.
 */
#[Fillable([
    'purchase_receipt_id',
    'name',
    'expense_category',
    'amount',
    'tax_amount',
    'tax_rate',
    'expense_id',
    'display_order',
])]
class PurchaseReceiptCharge extends Model
{
    protected $table = 'pos_purchase_receipt_charges';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }
}

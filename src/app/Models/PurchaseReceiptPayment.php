<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * AP — one payment recorded against a credit purchase receipt.
 *
 * Append-only ledger row (mirrors the loyalty-transaction shape): each payment
 * snapshots the amount paid and the outstanding balance LEFT afterward, so the
 * receipt's settlement history reads back even as the running total moves.
 * Never updated or deleted — a correction is a new row.
 *
 * Schema owned by pos_admin's 2026_07_26_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'purchase_receipt_id',
    'amount',
    'balance_after',
    'method',
    'note',
    'recorded_by_user_id',
    'paid_at',
])]
class PurchaseReceiptPayment extends Model
{
    protected $table = 'pos_purchase_receipt_payments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'paid_at' => 'datetime',
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

    /**
     * @return BelongsTo<PurchaseReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Phase 7a — payment tender (blueprint §10.8 + §16 Soft POS).
 *
 * One row per tender. Multi-tender split-payment orders
 * accumulate multiple rows; Phase 8 invariant requires
 * SUM(amount WHERE status=success) == order.grand_total for
 * any paid order.
 *
 * Soft POS reconciliation:
 *   - cash settles offline (status=success at write)
 *   - card requires bank connectivity. When offline, status
 *     stays at pending_reconciliation + the boolean flag is
 *     set; the admin matches it against the bank settlement
 *     file via the reconciled_by_admin_id / reconciled_at
 *     columns
 *
 * Schema owned by pos_admin's 2026_06_04_020000 migration.
 */
#[Fillable([
    'uuid',
    'order_id',
    'method',
    'amount',
    'change_given',
    'softpos_reference',
    'softpos_auth_code',
    'status',
    'pending_reconciliation',
    'reconciled_by_admin_id',
    'reconciled_at',
    'captured_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $table = 'pos_payments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:3',
            'change_given' => 'decimal:3',
            'status' => PaymentStatus::class,
            'pending_reconciliation' => 'boolean',
            'reconciled_at' => 'datetime',
            'captured_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The admin who matched this payment against a bank
     * settlement file. Only set on rows that started as
     * pending_reconciliation.
     *
     * @return BelongsTo<User, $this>
     */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_admin_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingReconciliation(Builder $query): Builder
    {
        return $query->where('pending_reconciliation', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Success->value);
    }
}

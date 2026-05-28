<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Phase 7a — order header (blueprint §10.8).
 *
 * The transactional spine. Phase 7 reports query this table;
 * Phase 8+ POS sale pipeline writes against it.
 *
 * Money columns (subtotal / discount_total / tax_total /
 * grand_total) cast as decimal:3 so the JSON layer preserves
 * OMR baisas precision. The invariant
 *   subtotal - discount_total + tax_total == grand_total
 * is enforced by the writing Action; we don't double-check it
 * at the model layer.
 *
 * Status + source + order_type cast to the closed enums so any
 * stray value at read time fails loudly instead of silently
 * propagating.
 *
 * Schema owned by pos_admin's 2026_06_04_010000 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'device_id',
    'staff_id',
    'customer_id',
    'table_id',
    'order_type',
    'status',
    'source',
    'plate_number',
    'subtotal',
    'discount_total',
    'tax_total',
    'grand_total',
    'opened_at',
    'closed_at',
    'client_event_id',
    'note',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $table = 'pos_orders';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'subtotal' => 'decimal:3',
            'discount_total' => 'decimal:3',
            'tax_total' => 'decimal:3',
            'grand_total' => 'decimal:3',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<PosStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PosStaff::class, 'staff_id');
    }

    /**
     * Line items, ordered by id so receipts + KDS render in the
     * order the cashier rang them up.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * Tender rows. A multi-tender order has multiple rows; the
     * Phase 8 invariant requires SUM(successful payments) ==
     * grand_total for any paid order.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * Convenience scope: orders captured in [from, to].
     * Reports use opened_at (the business timestamp), not
     * created_at — the latter lags for offline-replayed orders.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpenedBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query
            ->where('opened_at', '>=', $from)
            ->where('opened_at', '<=', $to);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Paid->value);
    }
}

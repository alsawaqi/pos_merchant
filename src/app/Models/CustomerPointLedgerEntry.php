<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointLedgerEntryType;
use Database\Factories\CustomerPointLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Phase 6b — one entry on the customer's points ledger.
 *
 * Append-only. The sum of points_delta per customer MUST equal
 * customers.points_balance at all times — enforced by
 * WritePointLedgerEntryAction wrapping both writes in one
 * DB transaction.
 *
 * No timestamps trait on purpose: we manage occurred_at +
 * created_at explicitly (mirrors StockMovement). Edits are
 * forbidden (corrections come as NEW Adjustment entries).
 *
 * Schema owned by pos_admin's 2026_06_02_010200 migration.
 */
#[Fillable([
    'uuid',
    'customer_id',
    'company_id',
    'entry_type',
    'points_delta',
    'balance_after',
    'reason',
    'reference_type',
    'reference_id',
    'recorded_by_user_id',
    'occurred_at',
])]
class CustomerPointLedgerEntry extends Model
{
    /** @use HasFactory<CustomerPointLedgerEntryFactory> */
    use HasFactory;

    protected $table = 'pos_customer_point_ledger';

    // We manage created_at + occurred_at explicitly (no
    // updated_at column on the table either).
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => PointLedgerEntryType::class,
            'points_delta' => 'integer',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Polymorphic link to the triggering entity. Phase 8 Orders
     * land here on earn entries; Phase 7+ refunds on refund_in
     * entries.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

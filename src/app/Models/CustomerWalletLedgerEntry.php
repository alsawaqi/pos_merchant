<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletLedgerEntryType;
use Database\Factories\CustomerWalletLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Phase 6b — one entry on the customer's wallet ledger.
 *
 * Same shape as CustomerPointLedgerEntry; differs only in the
 * value type (decimal:3 OMR vs integer points).
 *
 * Append-only. SUM(amount_delta) per customer == customers
 * .wallet_balance at all times.
 *
 * Schema owned by pos_admin's 2026_06_02_010300 migration.
 */
#[Fillable([
    'uuid',
    'customer_id',
    'company_id',
    'entry_type',
    'amount_delta',
    'balance_after',
    'reason',
    'reference_type',
    'reference_id',
    'recorded_by_user_id',
    'occurred_at',
])]
class CustomerWalletLedgerEntry extends Model
{
    /** @use HasFactory<CustomerWalletLedgerEntryFactory> */
    use HasFactory;

    protected $table = 'pos_customer_wallet_ledger';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => WalletLedgerEntryType::class,
            'amount_delta' => 'decimal:3',
            'balance_after' => 'decimal:3',
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
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LoyaltyAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Loyalty refactor — a customer's account under one rule
 * (blueprint §10.6).
 *
 * Holds the denormalised running balances (stamp_count +
 * point_balance) kept in lock-step with SUM(transactions) by
 * WriteLoyaltyTransactionAction inside a row-locked transaction.
 *
 * Composite-unique on (customer_id, loyalty_rule_id) — one
 * account per customer per rule. EnsureLoyaltyAccountAction is
 * the find-or-create path.
 *
 * Schema owned by pos_admin's 2026_06_08_010100 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'customer_id',
    'loyalty_rule_id',
    'stamp_count',
    'point_balance',
    'last_activity_at',
])]
class LoyaltyAccount extends Model
{
    /** @use HasFactory<LoyaltyAccountFactory> */
    use HasFactory;

    protected $table = 'pos_loyalty_accounts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamp_count' => 'integer',
            'point_balance' => 'integer',
            'last_activity_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<LoyaltyRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class, 'loyalty_rule_id');
    }

    /**
     * @return HasMany<LoyaltyTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}

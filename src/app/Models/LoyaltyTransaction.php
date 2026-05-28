<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use Database\Factories\LoyaltyTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Loyalty refactor — an append-only loyalty ledger entry
 * (blueprint §10.6). Replaces the Phase 6b point ledger.
 *
 * Never updated, never deleted — corrections come as NEW `adjust`
 * rows. A single row may move points, stamps, or both. The
 * balance_after_* columns hold the post-application running
 * balances so a history view never re-sums prior rows.
 *
 * Schema owned by pos_admin's 2026_06_08_010200 migration.
 */
#[Fillable([
    'uuid',
    'company_id',
    'loyalty_account_id',
    'type',
    'points_delta',
    'stamps_delta',
    'balance_after_points',
    'balance_after_stamps',
    'reason',
    'order_id',
    'recorded_by_user_id',
    'occurred_at',
])]
class LoyaltyTransaction extends Model
{
    /** @use HasFactory<LoyaltyTransactionFactory> */
    use HasFactory;

    protected $table = 'pos_loyalty_transactions';

    public $timestamps = false; // explicit created_at + occurred_at

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoyaltyTransactionType::class,
            'points_delta' => 'integer',
            'stamps_delta' => 'integer',
            'balance_after_points' => 'integer',
            'balance_after_stamps' => 'integer',
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

    /**
     * @return BelongsTo<LoyaltyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
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
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Immutable merchant resolution of one API-001 loyalty shortfall marker.
 *
 * Pending is represented by the absence of this row. Creating the row marks
 * the linked zero-delta ADJUST as resolved without ever updating the
 * append-only loyalty ledger itself.
 */
#[Fillable([
    'uuid',
    'company_id',
    'loyalty_transaction_id',
    'resolved_by_user_id',
    'resolution_note',
    'resolved_at',
    'created_at',
])]
class LoyaltyShortfallReview extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'pos_loyalty_shortfall_reviews';

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
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

        static::updating(static function (): never {
            throw new RuntimeException('Loyalty shortfall reviews are immutable.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Loyalty shortfall reviews cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<LoyaltyTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTransaction::class, 'loyalty_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}

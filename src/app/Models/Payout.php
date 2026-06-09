<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2 #17 (Phase B) — read-only view of the merchant's payouts (pos_payouts).
 * Schema owned + written by pos_admin (the platform creates + settles payouts);
 * the merchant portal only LISTS its own. net_amount is what the platform pays;
 * the platform/bank/other amounts are the snapshot deductions.
 */
class Payout extends Model
{
    protected $table = 'pos_payouts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'paid_at' => 'datetime',
            'gross_amount' => 'decimal:3',
            'platform_amount' => 'decimal:3',
            'bank_amount' => 'decimal:3',
            'other_amount' => 'decimal:3',
            'net_amount' => 'decimal:3',
            'sales_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

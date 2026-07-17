<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B — read-only view of the merchant's commission invoices
 * (pos_commission_invoices). Schema owned + written by pos_admin (the platform
 * issues + collects invoices); the merchant portal only LISTS its own. total_owed
 * is what the merchant owes the platform on its cash/bank_pos sales; the
 * platform/other amounts are the snapshot breakdown, merchant_amount is what the
 * merchant kept. The reverse direction of {@see Payout}.
 */
class CommissionInvoice extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_commission_invoices';

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
            'voided_at' => 'datetime',
            'gross_amount' => 'decimal:3',
            'cash_gross' => 'decimal:3',
            'bank_pos_gross' => 'decimal:3',
            'platform_amount' => 'decimal:3',
            'other_amount' => 'decimal:3',
            'merchant_amount' => 'decimal:3',
            'total_owed' => 'decimal:3',
            'sales_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

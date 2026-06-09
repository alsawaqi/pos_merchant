<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-company merchant POS policy (pos_company_settings). A generic key/value
 * store the merchant portal writes — distinct from the admin-owned, read-only
 * pos_companies.settings. Schema is owned by pos_admin's 2026_06_29 migration;
 * this app fully manages the rows (mirrors the pos_expense_categories split).
 *
 * value is JSON (a scalar, list, or object). v2 #14's first key is
 * `order_cancel_positions` → a list of staff positions allowed to cancel a
 * completed order at the POS; pos_api emits it in /device/config.
 */
class CompanySetting extends Model
{
    protected $table = 'pos_company_settings';

    protected $guarded = [];

    public const KEY_ORDER_CANCEL_POSITIONS = 'order_cancel_positions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}

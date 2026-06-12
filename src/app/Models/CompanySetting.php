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
     * P-F1 — the staff positions whose PIN authorizes sensitive POS actions
     * (comps, cancellations, gifts), the manager-fingerprint fallback.
     * pos_api emits it in /device/config and enforces it on
     * /device/auth/verify-manager-pin.
     */
    public const KEY_MANAGER_APPROVAL_POSITIONS = 'manager_approval_positions';

    /**
     * P-F6 — the staff positions allowed to open the Reports dashboard on
     * the POS device. pos_api emits it in /device/config; the DEVICE gates
     * its Reports screen on the list.
     */
    public const KEY_REPORTS_POSITIONS = 'reports_positions';

    /**
     * P-F8 — merchant-defined order numbering. JSON object
     * {enabled: bool, prefix: string(<=8, may be ''), pad: int 3..6,
     *  scope: 'branch'|'company', daily_reset: bool}. pos_api emits it in
     * /device/config (settings.order_numbering) and allocates numbers on
     * POST /device/orders/next-number from pos_order_sequences.
     */
    public const KEY_ORDER_NUMBERING = 'order_numbering';

    /**
     * P-G1 — the staff positions allowed to open the Kitchen production
     * section on the POS device (start/finish cooked-product batches).
     * pos_api emits it in /device/config; the DEVICE gates its Kitchen
     * screen on the list.
     */
    public const KEY_KITCHEN_POSITIONS = 'kitchen_positions';

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

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's StaffPosition. Kept in sync — the value
 * strings ARE the contract that survives across the two apps'
 * reads of `pos_staff.position`.
 *
 * If you add a case here, add it in pos_admin and ALSO in:
 *   - resources/js/lib/staff.ts (TS mirror used by the dropdowns)
 *   - locales/{en,ar}.json `pos_staff.positions.*` entries
 */
enum StaffPosition: string
{
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';
    case Manager = 'manager';
    case Supervisor = 'supervisor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

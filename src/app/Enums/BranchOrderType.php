<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's BranchOrderType. Same string values —
 * the `pos_branches.default_order_type` column carries one of
 * these and the Main POS top-bar segmented control (blueprint
 * §6.3 / §6.4) defaults to it when a cashier opens a new ticket.
 *
 * Merchant-editable in Phase 4.7 — different verticals pick
 * different defaults (cafe → Quick, restaurant → DineIn,
 * ghost kitchen → Delivery).
 */
enum BranchOrderType: string
{
    case Quick = 'quick';
    case DineIn = 'dine_in';
    case ToGo = 'to_go';
    case Delivery = 'delivery';
    case Car = 'car';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

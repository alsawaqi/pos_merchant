<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — order_type catalogue (blueprint §5.3.1 + §10.8).
 *
 *   quick     — counter sale, no table, customer walks away
 *               with the order. Most cafe sales fall here.
 *   dine_in   — customer eats at a table; order ties to a
 *               pos_tables row.
 *   to_go     — packed for take-away. Distinct from quick
 *               because reports break it out (§5.11.1
 *               Sales Report by order_type).
 *   delivery  — customer ordered remotely (Talabat / Otlob /
 *               own driver). Phase 6c provider pricing
 *               resolves the right amount per provider.
 *   car       — drive-thru / car-side pickup. The cashier
 *               identifies the customer via plate (§5.7.3);
 *               order.plate_number is set even if customer_id
 *               is null.
 */
enum OrderType: string
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
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

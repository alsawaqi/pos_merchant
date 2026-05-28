<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — per-line order item lifecycle (blueprint §10.8).
 *
 * Independent from the parent order's status: a multi-item
 * order can have one item sent_to_kitchen and another already
 * served while the order itself is still in status=kitchen.
 *
 *   open             — item added; not yet sent to kitchen
 *   sent_to_kitchen  — kitchen display received the item
 *   ready            — kitchen marked it ready for pickup
 *   served           — handed to the customer (dine-in) or
 *                      packed (to-go)
 *   void             — line cancelled (manager approval per
 *                      §12)
 */
enum OrderItemStatus: string
{
    case Open = 'open';
    case SentToKitchen = 'sent_to_kitchen';
    case Ready = 'ready';
    case Served = 'served';
    case Void = 'void';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

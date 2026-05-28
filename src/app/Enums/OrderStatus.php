<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — order lifecycle states (blueprint §10.8).
 *
 *   open       — cashier started a new order; items added
 *                but no payment yet
 *   held       — cashier paused the order to take a different
 *                customer (e.g. drive-thru queue)
 *   kitchen    — order sent to kitchen display, awaiting
 *                fulfillment (only meaningful for products
 *                that need preparation)
 *   paid       — payment captured, order complete
 *   void       — cancelled before payment (manager approval
 *                required per §12 permission matrix)
 *   refunded   — partial or full money returned after payment
 *
 * isTerminal() returns true for paid / void / refunded — the
 * UI uses this to switch into a read-only presentation and to
 * hide write-action buttons.
 *
 * The transition gates are enforced by the Phase 8 OrderAction
 * suite (not by the DB), same pattern as Phase 5c restock
 * requests.
 */
enum OrderStatus: string
{
    case Open = 'open';
    case Held = 'held';
    case Kitchen = 'kitchen';
    case Paid = 'paid';
    case Void = 'void';
    case Refunded = 'refunded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * True when no further state transitions are possible.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Void, self::Refunded => true,
            default => false,
        };
    }
}

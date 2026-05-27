<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5c — restock-request lifecycle states (blueprint §5.6.5).
 *
 * Transitions are gated by the Action layer; the column itself
 * is just a string the model casts to this enum. State machine:
 *
 *   Draft ──Submit──> Submitted ──Approve──> Approved ──Allocate──> Fulfilled (TERMINAL)
 *     │                  │                                              ^
 *     │                  └──Reject──> Rejected (TERMINAL)              │
 *     │                                                                 │
 *     └──Cancel──> Cancelled (TERMINAL) ←──Cancel── (also from Submitted)
 *
 * Once a request is Approved, the requester can NO LONGER
 * cancel it — they must ask HQ to reject/refund. This mirrors
 * the real-world expectation that "approved" means HQ has
 * already committed to the inventory.
 *
 * isTerminal() helps the UI hide write-action buttons on
 * dead states.
 */
enum RestockRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * True when no further state transitions are possible.
     * The UI uses this to switch the row into a read-only
     * presentation and to hide action buttons.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fulfilled, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }
}

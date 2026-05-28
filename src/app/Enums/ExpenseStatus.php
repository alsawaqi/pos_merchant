<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6 backfill — expense review status (blueprint §5.10).
 *
 *   recorded — logged (by POS staff or a portal user), awaiting
 *              merchant review. Counts toward net-profit.
 *   reviewed — merchant approved it (optionally annotated).
 *              Counts toward net-profit.
 *   rejected — merchant rejected it with a reason. EXCLUDED from
 *              the net-profit rollup, retained for the audit trail.
 */
enum ExpenseStatus: string
{
    case Recorded = 'recorded';
    case Reviewed = 'reviewed';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

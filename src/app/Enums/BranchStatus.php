<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirror of pos_admin's BranchStatus. Same string values — the
 * `pos_branches.status` column carries one of these and both apps
 * read it.
 *
 * `inactive` means the branch is paused (no POS orders, no new
 * device assignments) without being soft-deleted. Reversible by
 * flipping back to `active`.
 *
 * Toggling between the two on the merchant side is gated behind
 * `MerchantPermission.BranchesTransitionStatus` (SuperAdmin
 * only) — distinct from `BranchesUpdate` because deactivating a
 * branch has billing + fleet implications that Manager-tier
 * users shouldn't trigger unilaterally.
 */
enum BranchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

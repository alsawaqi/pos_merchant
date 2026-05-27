<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permission keys for the merchant portal. Mirrors the role of
 * pos_admin's PlatformPermission enum — every action that needs
 * gating reads from this catalogue, and every role's spatie
 * permission set in {@see \App\Actions\Admin\SeedMerchantRolesAction}
 * is built from these values.
 *
 * Phase 4.5 scope: portal-user CRUD. Subsequent phases add their
 * own keys (pos_staff.*, branches.*, floors.*, categories.*,
 * products.*, reports.*, etc.) and surface them in the same role
 * matrix.
 */
enum MerchantPermission: string
{
    // Portal users — the people who log into THIS merchant portal.
    // Distinct from POS staff (who use the Android app + a PIN).
    case PortalUsersView = 'portal_users.view';
    case PortalUsersInvite = 'portal_users.invite';
    case PortalUsersUpdate = 'portal_users.update';
    case PortalUsersRevoke = 'portal_users.revoke';

    // POS staff — the PIN-authenticated workforce that uses the
    // Android device (cashiers, waiters, kitchen, supervisors,
    // on-floor managers). Distinct from portal users above.
    // `revoke` is the umbrella for suspend / reactivate /
    // terminate — three actions but one risk class (taking the
    // PIN offline), reflected by one permission.
    case PosStaffView = 'pos_staff.view';
    case PosStaffCreate = 'pos_staff.create';
    case PosStaffUpdate = 'pos_staff.update';
    case PosStaffRevoke = 'pos_staff.revoke';

    // Branches — merchant-side CRUD on their OWN company's
    // branches (rename, edit hours, change contact details). No
    // create / delete on the merchant side; those are admin
    // operations because they have CR/regulatory implications
    // and downstream device-fleet effects. `transition_status`
    // is split out from `update` because deactivating a branch
    // stops POS orders + bills, a much sharper blast radius
    // than renaming or fixing the manager phone.
    case BranchesView = 'branches.view';
    case BranchesUpdate = 'branches.update';
    case BranchesTransitionStatus = 'branches.transition_status';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

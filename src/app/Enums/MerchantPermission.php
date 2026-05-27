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

    // Roles & permissions — the meta-control. `view` lets a
    // user browse the role list (e.g. to know what role to
    // request); `manage` is the sharp tool that lets them
    // create / edit / delete roles AND assign roles to portal
    // users. Defaults to SuperAdmin-only; merchant SuperAdmin
    // can hand it out to a deputy by editing a custom role.
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // Phase 5 — floor plan. One catalog for both floors AND
    // tables (the survey concluded splitting them would create
    // permission keys nobody ever assigns separately —
    // you can't edit a table without seeing its floor).
    case FloorPlanView = 'floor_plan.view';
    case FloorPlanManage = 'floor_plan.manage';

    // Phase 6 — catalogue. One catalog for both categories AND
    // products (same rationale as floor plan — nobody manages
    // products without also editing categories). Add-on /
    // modifier permissions (Phase 4.9) reuse these keys rather
    // than getting granular ones.
    case CatalogueView = 'catalogue.view';
    case CatalogueManage = 'catalogue.manage';

    // Phase 5a — inventory. One catalog for ingredients,
    // suppliers, branch stock + movements (manage covers all
    // four). Same rationale as the others: splitting into
    // sub-permissions would create keys nobody ever assigns
    // separately. Adjust / Restock / Waste / Loss all gate on
    // inventory.manage.
    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

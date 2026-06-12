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

    // Phase 5c — restock-request workflow. Two keys, deliberately
    // separated:
    //   create: branch-level staff (incl. branch manager) submit
    //           requests for what they need from HQ.
    //   review: HQ-level staff (incl. inventory manager) approve,
    //           reject, cancel, or allocate submitted requests.
    // Allocation writes stock movements at the requesting branch
    // — gated by 'review' rather than 'inventory.manage' so the
    // restock workflow stays independently controllable from raw
    // stock adjustments.
    case RestockRequestCreate = 'inventory.restock_request.create';
    case RestockRequestReview = 'inventory.restock_request.review';

    // Phase 6a — customer book. Read access is generous because
    // the cashier supervisor / viewer roles often need to look
    // up a customer for reporting; write access is gated to
    // Manager / SuperAdmin (the role assigning a "customer
    // representative" can mint a custom role with manage). Note
    // that the Phase 7+ POS terminal (Android) will do its own
    // find-or-create on the cashier side via the device-auth
    // pipeline — those writes do NOT come through this portal
    // permission.
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    // Phase 6b — loyalty + wallet. Loyalty is money-adjacent
    // (points convert to OMR off the bill via the config; the
    // wallet IS OMR), so manage is deliberately tighter than
    // customers.manage. View is generous so reporting roles can
    // see balances at a glance; manage is the actual lever for
    // changing balances + editing the earn/redemption rates.
    case LoyaltyView = 'loyalty.view';
    case LoyaltyManage = 'loyalty.manage';

    // Phase 6d — discounts. Same risk class as loyalty (it
    // moves money off the bill at POS time), so we gate it
    // separately from catalogue. View is generous (reporting
    // roles need it for the §5.11.7 Discount Report); manage
    // is Manager + InventoryManager + SuperAdmin.
    case DiscountsView = 'discounts.view';
    case DiscountsManage = 'discounts.manage';

    // Phase 6 backfill — Expenses (blueprint §5.10).
    //   ExpensesView   gates the review-queue list + detail.
    //   ExpensesManage gates log / approve (review) / reject /
    //                  annotate. Tighter than view because it
    //                  touches money that feeds the net-profit
    //                  line of the Sales Report.
    case ExpensesView = 'expenses.view';
    case ExpensesManage = 'expenses.manage';

    // Phase 7b — reports + audit viewer (blueprint §13 Phase 7).
    //   ReportsView   gates the 10 reports + landing
    //   ReportsExport gates the queued Excel/PDF export flow
    //                  (large exports run 30s+; gating the
    //                  trigger protects the queue from a
    //                  runaway user)
    //   AuditLogView  gates the §5.12 merchant audit log viewer.
    //                  Separate from ReportsView because some
    //                  merchants grant reports broadly but want
    //                  the audit log restricted to managers.
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case AuditLogView = 'audit_log.view';

    // v2 #14 — order cancellation policy. `orders.cancel` gates configuring
    // WHICH staff positions may cancel a completed order at the POS — the policy
    // is emitted to the device (in /device/config) and enforced there. It is the
    // manage lever for the Order Cancellation settings page; no separate view
    // key (only a user who can change the policy needs to see it). Money-adjacent
    // (a void reverses loyalty / round-up / commission), so Manager + SuperAdmin.
    case OrdersCancel = 'orders.cancel';

    // P-G1 — kitchen production history. Read-only: batches are created /
    // finished / cancelled exclusively from the POS device (pos_api
    // validates fresh ingredient balances online); the portal only audits
    // them (who, what, quantities, std vs extra, duration). One view key —
    // there is nothing to manage.
    case ProductionView = 'production.view';

    // P-G6 — staff announcements (portal → POS devices). One key gates
    // composing, retracting AND reading the receipts page — it is a
    // management surface. The portal-to-portal INBOX is deliberately
    // ungated: every authed user can send/receive internal mail.
    case MessagesSend = 'messages.send';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

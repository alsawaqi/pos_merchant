/**
 * Mirror of {@link \App\Enums\MerchantPermission} + MerchantRole.
 * Keep in sync — referenced by the sidebar gate and inline UI
 * checks. The server is still the source of truth.
 */
export const MerchantPermission = {
    PortalUsersView: 'portal_users.view',
    PortalUsersInvite: 'portal_users.invite',
    PortalUsersUpdate: 'portal_users.update',
    PortalUsersRevoke: 'portal_users.revoke',
    // Phase 4.6 — PIN-authenticated POS staff. Revoke covers
    // suspend / reactivate / terminate as one risk class.
    PosStaffView: 'pos_staff.view',
    PosStaffCreate: 'pos_staff.create',
    PosStaffUpdate: 'pos_staff.update',
    PosStaffRevoke: 'pos_staff.revoke',
    // Phase 4.7 — merchant-side branches CRUD. Status flip is
    // split because deactivating a branch has billing + fleet
    // blast radius separate from a rename / hours / contact
    // edit.
    BranchesView: 'branches.view',
    BranchesUpdate: 'branches.update',
    BranchesTransitionStatus: 'branches.transition_status',
    // Phase 4.8 — role builder. RolesManage gates the
    // create/edit/delete role flows AND the assign-roles-to-
    // user flow. Defaults to SuperAdmin-only.
    RolesView: 'roles.view',
    RolesManage: 'roles.manage',
    // Phase 5 — floor plan. Both floors and tables.
    FloorPlanView: 'floor_plan.view',
    FloorPlanManage: 'floor_plan.manage',
    // Phase 6 — catalogue (menu). Both categories and products
    // under one gate; add-ons / modifiers (Phase 4.9) and
    // future price lists share the same key.
    CatalogueView: 'catalogue.view',
    CatalogueManage: 'catalogue.manage',
    // Phase 5a — inventory (ingredients, suppliers, branch
    // stock, movements). Single gate for all four because in
    // practice nobody manages stock without seeing ingredients
    // and vice-versa. Waste recording also falls under manage.
    InventoryView: 'inventory.view',
    InventoryManage: 'inventory.manage',
    // Phase 5c — restock workflow. Deliberately split:
    //   create → branch staff submit requests
    //   review → HQ approves/rejects/cancels/allocates
    // Allocation writes stock movements at the requesting
    // branch, gated by 'review' (not inventory.manage) so the
    // restock workflow stays independently controllable.
    RestockRequestCreate: 'inventory.restock_request.create',
    RestockRequestReview: 'inventory.restock_request.review',
    // Phase 6a — customer book. View is generous (Viewer +
    // CashierSupervisor + InventoryManager + Manager + SuperAdmin
    // all see). Manage is Manager + SuperAdmin only. The Phase 7+
    // POS terminal does its own find-or-create via the device-
    // auth pipeline; those writes don't pass through this gate.
    CustomersView: 'customers.view',
    CustomersManage: 'customers.manage',
    // Phase 6b — loyalty + wallet. Manage is a tighter gate
    // than customers.manage (it moves money / earned points).
    // View matches the customers.view audience for reporting.
    LoyaltyView: 'loyalty.view',
    LoyaltyManage: 'loyalty.manage',
    // Phase 6d — discount rules. Same risk class as loyalty
    // (money off the bill at POS time). View generous; manage
    // is Manager / InventoryManager / SuperAdmin.
    DiscountsView: 'discounts.view',
    DiscountsManage: 'discounts.manage',
    // Phase 6 backfill — expenses (§5.10). View generous;
    // manage (log / review / reject) is Manager / InventoryManager.
    ExpensesView: 'expenses.view',
    ExpensesManage: 'expenses.manage',
    // Phase 7b — reports + audit log viewer. View generous;
    // export + audit log are Manager+ tools.
    ReportsView: 'reports.view',
    ReportsExport: 'reports.export',
    AuditLogView: 'audit_log.view',
    // v2 #14 — order cancellation policy. Gates the Order Cancellation
    // settings page (which staff positions may cancel at the POS).
    OrdersCancel: 'orders.cancel',
    // P-G1 — kitchen production history (read-only; batches are written
    // exclusively by the POS device through pos_api).
    ProductionView: 'production.view',
} as const;

export type MerchantPermissionValue =
    (typeof MerchantPermission)[keyof typeof MerchantPermission];

export const MerchantRole = {
    SuperAdmin: 'merchant_super_admin',
    Manager: 'merchant_manager',
    InventoryManager: 'merchant_inventory_manager',
    CashierSupervisor: 'merchant_cashier_supervisor',
    Viewer: 'merchant_viewer',
} as const;

export type MerchantRoleValue = (typeof MerchantRole)[keyof typeof MerchantRole];

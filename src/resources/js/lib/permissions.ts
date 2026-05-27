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

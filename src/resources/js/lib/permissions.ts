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

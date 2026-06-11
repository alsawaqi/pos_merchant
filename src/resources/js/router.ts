import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import Login from '@/Pages/Auth/Login.vue';
import ChangePassword from '@/Pages/Auth/ChangePassword.vue';
import ForgotPassword from '@/Pages/Auth/ForgotPassword.vue';
import ResetPassword from '@/Pages/Auth/ResetPassword.vue';
import TwoFactorChallenge from '@/Pages/Auth/TwoFactorChallenge.vue';
import Profile from '@/Pages/Auth/Profile.vue';
import Dashboard from '@/Pages/Merchant/Dashboard.vue';
import OrdersIndex from '@/Pages/Merchant/Orders/Index.vue';
import BranchesIndex from '@/Pages/Merchant/Branches/Index.vue';
import BranchesShow from '@/Pages/Merchant/Branches/Show.vue';
import CatalogueIndex from '@/Pages/Merchant/Catalogue/Index.vue';
import TaxesIndex from '@/Pages/Merchant/Taxes/Index.vue';
import ExpenseCategoriesIndex from '@/Pages/Merchant/ExpenseCategories/Index.vue';
import CustomersIndex from '@/Pages/Merchant/Customers/Index.vue';
import CustomersShow from '@/Pages/Merchant/Customers/Show.vue';
import DiscountsIndex from '@/Pages/Merchant/Discounts/Index.vue';
import OffersIndex from '@/Pages/Merchant/Offers/Index.vue';
import ExpensesIndex from '@/Pages/Merchant/Expenses/Index.vue';
import LoyaltyIndex from '@/Pages/Merchant/Loyalty/Index.vue';
import FloorPlanIndex from '@/Pages/Merchant/FloorPlan/Index.vue';
import InventoryIndex from '@/Pages/Merchant/Inventory/Index.vue';
import PortalUsersIndex from '@/Pages/Merchant/PortalUsers/Index.vue';
import PosStaffIndex from '@/Pages/Merchant/PosStaff/Index.vue';
import RolesIndex from '@/Pages/Merchant/Roles/Index.vue';
import SettingsOrderCancellation from '@/Pages/Merchant/Settings/OrderCancellation.vue';
import SettingsOrderNumbering from '@/Pages/Merchant/Settings/OrderNumbering.vue';
import ReportsIndex from '@/Pages/Merchant/Reports/Index.vue';
import ReportsSales from '@/Pages/Merchant/Reports/Sales.vue';
import ReportsCustomers from '@/Pages/Merchant/Reports/Customers.vue';
import ReportsDiscounts from '@/Pages/Merchant/Reports/Discounts.vue';
import ReportsComps from '@/Pages/Merchant/Reports/Comps.vue';
import ReportsShifts from '@/Pages/Merchant/Reports/Shifts.vue';
import ReportsPayouts from '@/Pages/Merchant/Reports/Payouts.vue';
import ReportsProductPerformance from '@/Pages/Merchant/Reports/ProductPerformance.vue';
import ReportsRecipeCost from '@/Pages/Merchant/Reports/RecipeCost.vue';
import ReportsStaffActivity from '@/Pages/Merchant/Reports/StaffActivity.vue';
import ReportsInventoryConsumption from '@/Pages/Merchant/Reports/InventoryConsumption.vue';
import ReportsLossWaste from '@/Pages/Merchant/Reports/LossWaste.vue';
import ReportsRestockPurchasing from '@/Pages/Merchant/Reports/RestockPurchasing.vue';
import ReportsRoundUpDonation from '@/Pages/Merchant/Reports/RoundUpDonation.vue';
import AuditLogIndex from '@/Pages/Merchant/AuditLog/Index.vue';
import { authState, ensureAuthLoaded, resetAuthBootPromise } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        guestOnly?: boolean;
        requiresAuth?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guestOnly: true },
    },
    {
        // Phase D7 — self-service forgot password (email form).
        path: '/forgot-password',
        name: 'forgot-password',
        component: ForgotPassword,
        meta: { guestOnly: true },
    },
    {
        // Phase D7 — landing page for the emailed reset link
        // (?token=…&email=…).
        path: '/reset-password',
        name: 'reset-password',
        component: ResetPassword,
        meta: { guestOnly: true },
    },
    {
        // Phase D8 — TOTP code step. The login POST parks the
        // pending state server-side and redirects here; the page
        // bounces back to /login when nothing is pending.
        path: '/two-factor-challenge',
        name: 'two-factor-challenge',
        component: TwoFactorChallenge,
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'merchant.dashboard',
        component: Dashboard,
        meta: { requiresAuth: true },
    },
    {
        path: '/orders',
        name: 'merchant.orders',
        component: OrdersIndex,
        meta: { requiresAuth: true },
    },
    {
        // Portal Users — manage merchant's own team. Server-side
        // permission middleware is the real gate; the SPA hides
        // the sidebar entry when usePermissions().can() returns
        // false but a curious user pasting the URL still hits the
        // controller's authorize() check.
        path: '/portal-users',
        name: 'merchant.portal-users',
        component: PortalUsersIndex,
        meta: { requiresAuth: true },
    },
    {
        // POS Staff — PIN-authenticated workforce. Same gate
        // story as /portal-users: server-side middleware is the
        // real check, the SPA just hides the nav entry.
        path: '/pos-staff',
        name: 'merchant.pos-staff',
        component: PosStaffIndex,
        meta: { requiresAuth: true },
    },
    {
        // Branches — Phase 4.7. Server enforces the
        // branches.view permission; SPA hides the nav entry
        // for users without it.
        path: '/branches',
        name: 'merchant.branches',
        component: BranchesIndex,
        meta: { requiresAuth: true },
    },
    {
        // Branch detail (v2 #11): products, staff, devices, activity.
        path: '/branches/:uuid',
        name: 'merchant.branches.show',
        component: BranchesShow,
        meta: { requiresAuth: true },
    },
    {
        // Roles & Permissions — Phase 4.8. Server enforces
        // roles.view; SPA hides nav entry for users without it.
        path: '/roles',
        name: 'merchant.roles',
        component: RolesIndex,
        meta: { requiresAuth: true },
    },
    {
        // Floor Plan — Phase 5. Branch selector at top picks
        // which branch to manage; floors + tables for that
        // branch render below.
        path: '/floor-plan',
        name: 'merchant.floor-plan',
        component: FloorPlanIndex,
        meta: { requiresAuth: true },
    },
    {
        // Catalogue — Phase 6. Tabbed page: categories + products.
        // Server-side gates by catalogue.view.
        path: '/catalogue',
        name: 'merchant.catalogue',
        component: CatalogueIndex,
        meta: { requiresAuth: true },
    },
    {
        // Taxes — company-level tax settings the POS fetches via /device/config.
        // Server-side gates by catalogue.view (reused, like delivery providers).
        path: '/taxes',
        name: 'merchant.taxes',
        component: TaxesIndex,
        meta: { requiresAuth: true },
    },
    {
        // Expense categories (v2 #7) — company-managed; the POS fetches the
        // active set via /device/config. Server gates by expenses.view.
        path: '/expense-categories',
        name: 'merchant.expense-categories',
        component: ExpenseCategoriesIndex,
        meta: { requiresAuth: true },
    },
    {
        // Inventory — Phase 5a. Tabbed page: ingredients,
        // suppliers, per-branch stock, movement ledger.
        // Server-side gates by inventory.view.
        path: '/inventory',
        name: 'merchant.inventory',
        component: InventoryIndex,
        meta: { requiresAuth: true },
    },
    {
        // Customers — Phase 6a. Customer book + vehicle plates.
        // Server enforces customers.view; SPA hides the nav
        // entry for users without it.
        path: '/customers',
        name: 'merchant.customers',
        component: CustomersIndex,
        meta: { requiresAuth: true },
    },
    {
        // Customer 360 (v2 #8). Detail page: rollups, favorite item,
        // spend trend, loyalty/wallet, order history.
        path: '/customers/:uuid',
        name: 'merchant.customers.show',
        component: CustomersShow,
        meta: { requiresAuth: true },
    },
    {
        // Discounts — Phase 6d. Rules engine. Server enforces
        // discounts.view; SPA hides the nav for users without
        // it.
        path: '/discounts',
        name: 'merchant.discounts',
        component: DiscountsIndex,
        meta: { requiresAuth: true },
    },
    {
        // Offers — P-F9. type + config promotions the POS device
        // evaluates. Server enforces discounts.view (offers share the
        // discounts permission keys); SPA hides the nav without it.
        path: '/offers',
        name: 'merchant.offers',
        component: OffersIndex,
        meta: { requiresAuth: true },
    },
    {
        // Expenses — Phase 6 backfill (§5.10). POS-captured
        // expense review queue. Server enforces expenses.view;
        // SPA hides the nav entry for users without it.
        path: '/expenses',
        name: 'merchant.expenses',
        component: ExpensesIndex,
        meta: { requiresAuth: true },
    },
    {
        // Loyalty Rules — blueprint §5.8. visit_based + spend_based
        // rule config. Server enforces loyalty.view; SPA hides the
        // nav entry for users without it.
        path: '/loyalty',
        name: 'merchant.loyalty',
        component: LoyaltyIndex,
        meta: { requiresAuth: true },
    },

    // -------- Phase 7b-6 — Reports landing + per-report pages --
    // The landing page lists every blueprint report (§5.11.1–10)
    // as a tile; each tile routes to a dedicated /reports/<key>
    // page. Server-side reports.view gates every fetch; the SPA
    // hides the sidebar entry too.
    { path: '/reports', name: 'merchant.reports', component: ReportsIndex, meta: { requiresAuth: true } },
    { path: '/reports/sales', name: 'merchant.reports.sales', component: ReportsSales, meta: { requiresAuth: true } },
    { path: '/reports/customers', name: 'merchant.reports.customers', component: ReportsCustomers, meta: { requiresAuth: true } },
    { path: '/reports/discounts', name: 'merchant.reports.discounts', component: ReportsDiscounts, meta: { requiresAuth: true } },
    { path: '/reports/comps', name: 'merchant.reports.comps', component: ReportsComps, meta: { requiresAuth: true } },
    { path: '/reports/shifts', name: 'merchant.reports.shifts', component: ReportsShifts, meta: { requiresAuth: true } },
    { path: '/reports/payouts', name: 'merchant.reports.payouts', component: ReportsPayouts, meta: { requiresAuth: true } },
    { path: '/reports/product-performance', name: 'merchant.reports.product-performance', component: ReportsProductPerformance, meta: { requiresAuth: true } },
    { path: '/reports/recipe-cost', name: 'merchant.reports.recipe-cost', component: ReportsRecipeCost, meta: { requiresAuth: true } },
    { path: '/reports/staff-activity', name: 'merchant.reports.staff-activity', component: ReportsStaffActivity, meta: { requiresAuth: true } },
    { path: '/reports/inventory-consumption', name: 'merchant.reports.inventory-consumption', component: ReportsInventoryConsumption, meta: { requiresAuth: true } },
    { path: '/reports/loss-waste', name: 'merchant.reports.loss-waste', component: ReportsLossWaste, meta: { requiresAuth: true } },
    { path: '/reports/restock-purchasing', name: 'merchant.reports.restock-purchasing', component: ReportsRestockPurchasing, meta: { requiresAuth: true } },
    { path: '/reports/round-up-donation', name: 'merchant.reports.round-up-donation', component: ReportsRoundUpDonation, meta: { requiresAuth: true } },

    {
        // Order Cancellation policy (v2 #14) — which staff positions
        // may cancel an order at the POS. Server enforces orders.cancel
        // on both GET + PUT; SPA hides the nav entry for users without
        // it.
        path: '/settings/order-cancellation',
        name: 'merchant.settings.order-cancellation',
        component: SettingsOrderCancellation,
        meta: { requiresAuth: true },
    },

    {
        // Order Numbering policy (P-F8) — how POS order numbers look
        // (prefix + zero-padded counter), per-branch vs company-wide
        // sequence, optional daily reset. Server enforces orders.cancel
        // on both GET + PUT; SPA hides the nav entry for users without it.
        path: '/settings/order-numbering',
        name: 'merchant.settings.order-numbering',
        component: SettingsOrderNumbering,
        meta: { requiresAuth: true },
    },

    // Audit log viewer (§5.12). Server-side audit_log.view gates
    // this; the sidebar entry is independently gated on the same
    // permission.
    { path: '/audit-log', name: 'merchant.audit-log', component: AuditLogIndex, meta: { requiresAuth: true } },

    // Self-service / forced password change. requiresAuth so only a
    // signed-in user reaches it; the beforeEach guard force-redirects
    // here while must_change_password is set on a freshly-minted account.
    { path: '/change-password', name: 'merchant.change-password', component: ChangePassword, meta: { requiresAuth: true } },

    // Phase D7 — My Profile (name edit + read-only email/roles +
    // change-password link). Reached from the header user chip.
    { path: '/profile', name: 'merchant.profile', component: Profile, meta: { requiresAuth: true } },

    {
        // Catch-all → bounce to the dashboard (server-side guard
        // handles the auth check). Mirrors pos_admin's pattern.
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    if (to.meta.requiresAuth) {
        if (!authState.loaded || !authState.user) {
            resetAuthBootPromise();
            await ensureAuthLoaded();
        }
        if (!authState.user) {
            return {
                name: 'login',
                query: { redirect: to.fullPath },
                replace: true,
            };
        }

        // Forced first-login password change: a freshly-minted account
        // (must_change_password set by the platform admin) must choose
        // its own password before it can reach anything else.
        if (authState.user.must_change_password && to.name !== 'merchant.change-password') {
            return { name: 'merchant.change-password', replace: true };
        }
    }

    if (to.meta.guestOnly && authState.user) {
        // Already signed in — bounce away from /login.
        return { name: 'merchant.dashboard', replace: true };
    }

    return true;
});

router.afterEach((to) => {
    document.title = to.name === 'login'
        ? 'Login — MITHQAL Merchant Portal'
        : 'MITHQAL Merchant Portal';
});

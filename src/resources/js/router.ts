import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import Login from '@/Pages/Auth/Login.vue';
import Dashboard from '@/Pages/Merchant/Dashboard.vue';
import BranchesIndex from '@/Pages/Merchant/Branches/Index.vue';
import CatalogueIndex from '@/Pages/Merchant/Catalogue/Index.vue';
import CustomersIndex from '@/Pages/Merchant/Customers/Index.vue';
import DiscountsIndex from '@/Pages/Merchant/Discounts/Index.vue';
import FloorPlanIndex from '@/Pages/Merchant/FloorPlan/Index.vue';
import InventoryIndex from '@/Pages/Merchant/Inventory/Index.vue';
import PortalUsersIndex from '@/Pages/Merchant/PortalUsers/Index.vue';
import PosStaffIndex from '@/Pages/Merchant/PosStaff/Index.vue';
import RolesIndex from '@/Pages/Merchant/Roles/Index.vue';
import ReportsIndex from '@/Pages/Merchant/Reports/Index.vue';
import ReportsSales from '@/Pages/Merchant/Reports/Sales.vue';
import ReportsCustomers from '@/Pages/Merchant/Reports/Customers.vue';
import ReportsDiscounts from '@/Pages/Merchant/Reports/Discounts.vue';
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
        path: '/',
        name: 'merchant.dashboard',
        component: Dashboard,
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
        // Discounts — Phase 6d. Rules engine. Server enforces
        // discounts.view; SPA hides the nav for users without
        // it.
        path: '/discounts',
        name: 'merchant.discounts',
        component: DiscountsIndex,
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
    { path: '/reports/product-performance', name: 'merchant.reports.product-performance', component: ReportsProductPerformance, meta: { requiresAuth: true } },
    { path: '/reports/recipe-cost', name: 'merchant.reports.recipe-cost', component: ReportsRecipeCost, meta: { requiresAuth: true } },
    { path: '/reports/staff-activity', name: 'merchant.reports.staff-activity', component: ReportsStaffActivity, meta: { requiresAuth: true } },
    { path: '/reports/inventory-consumption', name: 'merchant.reports.inventory-consumption', component: ReportsInventoryConsumption, meta: { requiresAuth: true } },
    { path: '/reports/loss-waste', name: 'merchant.reports.loss-waste', component: ReportsLossWaste, meta: { requiresAuth: true } },
    { path: '/reports/restock-purchasing', name: 'merchant.reports.restock-purchasing', component: ReportsRestockPurchasing, meta: { requiresAuth: true } },
    { path: '/reports/round-up-donation', name: 'merchant.reports.round-up-donation', component: ReportsRoundUpDonation, meta: { requiresAuth: true } },

    // Audit log viewer (§5.12). Server-side audit_log.view gates
    // this; the sidebar entry is independently gated on the same
    // permission.
    { path: '/audit-log', name: 'merchant.audit-log', component: AuditLogIndex, meta: { requiresAuth: true } },

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

import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import Login from '@/Pages/Auth/Login.vue';
import Dashboard from '@/Pages/Merchant/Dashboard.vue';
import BranchesIndex from '@/Pages/Merchant/Branches/Index.vue';
import CatalogueIndex from '@/Pages/Merchant/Catalogue/Index.vue';
import CustomersIndex from '@/Pages/Merchant/Customers/Index.vue';
import FloorPlanIndex from '@/Pages/Merchant/FloorPlan/Index.vue';
import InventoryIndex from '@/Pages/Merchant/Inventory/Index.vue';
import PortalUsersIndex from '@/Pages/Merchant/PortalUsers/Index.vue';
import PosStaffIndex from '@/Pages/Merchant/PosStaff/Index.vue';
import RolesIndex from '@/Pages/Merchant/Roles/Index.vue';
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

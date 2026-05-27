import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import Login from '@/Pages/Auth/Login.vue';
import Dashboard from '@/Pages/Merchant/Dashboard.vue';
import PortalUsersIndex from '@/Pages/Merchant/PortalUsers/Index.vue';
import PosStaffIndex from '@/Pages/Merchant/PosStaff/Index.vue';
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

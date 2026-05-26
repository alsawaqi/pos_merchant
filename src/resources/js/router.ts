import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import Login from '@/Pages/Auth/Login.vue';
import Dashboard from '@/Pages/Merchant/Dashboard.vue';
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

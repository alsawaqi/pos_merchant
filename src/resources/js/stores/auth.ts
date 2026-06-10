/**
 * SPA auth state — mirror of pos_admin's auth.ts pattern.
 *
 * The login + logout boundaries are traditional form POSTs handled
 * by the browser, so this store doesn't perform those — it just
 * reflects "who is currently signed in" so the in-SPA UI (sidebar,
 * permission checks, layouts) can react.
 *
 * The synchronous boot reads window.__INITIAL_AUTH__ (injected by
 * the blade view) so the very first render already knows the
 * answer. Subsequent updates flow from /auth/user XHRs after a
 * route transition.
 */

import { reactive } from 'vue';
import { ApiError, apiGet } from '@/lib/api';

export interface AuthUser {
    id: number | string;
    name: string | null;
    email: string | null;
    user_type: string | null;
    status: string | null;
    company_id: number | null;
    locale: string | null;
    /** Spatie role names under the user's company team scope. */
    roles?: string[];
    /** Flat permission names ('portal_users.view', etc.). */
    permissions?: string[];
    /**
     * Set on a freshly-minted account (the platform admin gives a
     * temporary password). The router forces a self-chosen password
     * before the app is usable; cleared after a successful change.
     */
    must_change_password?: boolean;
}

export interface AuthSession {
    idle_timeout_minutes: number;
    csrf_token: string;
}

interface AuthState {
    user: AuthUser | null;
    session: AuthSession | null;
    loaded: boolean;
}

export const authState = reactive<AuthState>({
    user: null,
    session: null,
    loaded: false,
});

/**
 * Read window.__INITIAL_AUTH__ (set by app.blade.php) and populate
 * the store synchronously. Called once from app.ts before mount.
 */
export function hydrateAuthFromInitial(): void {
    const initial = (window as Window & { __INITIAL_AUTH__?: unknown }).__INITIAL_AUTH__;

    if (!initial || typeof initial !== 'object') {
        authState.loaded = true;
        return;
    }

    const payload = initial as { authenticated?: boolean; user?: AuthUser | null };
    if (payload.authenticated && payload.user) {
        authState.user = payload.user;
    } else {
        authState.user = null;
    }
    authState.loaded = true;
}

/**
 * Fetch /auth/user and refresh the store. Called by the router
 * guard when a navigation requires auth and we don't yet know the
 * answer for sure. Failure to load (401, network) treats the user
 * as unauthenticated.
 */
let inflight: Promise<void> | null = null;

export function ensureAuthLoaded(): Promise<void> {
    if (inflight) {
        return inflight;
    }

    inflight = (async () => {
        try {
            const response = await apiGet<{ user: AuthUser; session: AuthSession }>('/auth/user', {
                skipAuthInterceptor: true,
            });
            authState.user = response.user;
            authState.session = response.session;
            authState.loaded = true;
        } catch (err) {
            authState.user = null;
            authState.session = null;
            authState.loaded = true;
            if (!(err instanceof ApiError) || err.status !== 401) {
                // Re-raise non-auth errors so the caller can decide
                // whether to surface them. 401 is normal state ("not
                // signed in") so we swallow it.
                throw err;
            }
        } finally {
            inflight = null;
        }
    })();

    return inflight;
}

/** Reset the cached inflight Promise — used right after a login form POST. */
export function resetAuthBootPromise(): void {
    inflight = null;
    authState.loaded = false;
}

/**
 * Clear the forced-change flag in-place after a successful self-service
 * password change, so the router guard stops redirecting to the
 * change-password page without needing a full /auth/user refetch.
 */
export function clearMustChangePassword(): void {
    if (authState.user) {
        authState.user.must_change_password = false;
    }
}

/**
 * Reflect a successful PATCH /auth/profile name change in-place so
 * the layout's user chip updates without a full /auth/user refetch.
 */
export function setAuthUserName(name: string): void {
    if (authState.user) {
        authState.user.name = name;
    }
}

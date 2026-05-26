/**
 * Centralised permission/role lookup for the merchant SPA.
 *
 * The current user's roles + permissions come back on the
 * /auth/user payload as flat string arrays (when we extend
 * AuthenticatedSessionController to include them — for v1 the
 * SPA assumes the signed-in user has the permissions the server
 * already enforces, so this hook is forward-looking).
 *
 * For Phase 4.5, we expose can() / canAny() helpers that the
 * sidebar + buttons can call. The actual permission array is
 * stored on authState.user.permissions (added when we surface
 * it from the backend). Until then, can() returns true for the
 * SuperAdmin role as a temporary shortcut — every other call
 * site renders nothing because server-side enforcement is the
 * real gate.
 */

import { computed } from 'vue';
import { authState, type AuthUser } from '@/stores/auth';
import { MerchantRole } from '@/lib/permissions';

interface AuthUserWithRoles extends AuthUser {
    roles?: string[];
    permissions?: string[];
}

export function usePermissions() {
    const user = computed<AuthUserWithRoles | null>(
        () => authState.user as AuthUserWithRoles | null,
    );

    const isSuperAdmin = computed(() =>
        (user.value?.roles ?? []).includes(MerchantRole.SuperAdmin),
    );

    function can(permission: string): boolean {
        const perms = user.value?.permissions ?? [];
        if (perms.includes(permission)) {
            return true;
        }
        // Short-circuit: super admin has every permission. Mirrors
        // the Gate::before super-admin escape in the back-end.
        return isSuperAdmin.value;
    }

    function canAny(permissions: readonly string[]): boolean {
        if (permissions.length === 0) {
            return true;
        }
        return permissions.some((p) => can(p));
    }

    function hasRole(role: string): boolean {
        return (user.value?.roles ?? []).includes(role);
    }

    return { user, can, canAny, hasRole, isSuperAdmin };
}

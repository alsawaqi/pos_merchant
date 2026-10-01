/**
 * Typed client for the Portal Users endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Portal\PortalUsersController}.
 * All endpoints are auto-scoped server-side to the actor's
 * company — no merchant uuid in the URL.
 *
 * Owner follow-up 2026-10-01: the create + reset-password responses
 * carry a one-time `set_password_link` OUTSIDE the `data` envelope —
 * never a password. Reset also blocks the old password at once.
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { MerchantRoleValue } from '@/lib/permissions';

export type PortalUserStatus = 'active' | 'inactive' | 'suspended';

export interface PortalUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: PortalUserStatus | null;
    /**
     * Backward-compatible single role — first entry of `roles`,
     * or null when the user has no roles. New consumers should
     * read `roles` directly.
     */
    role: MerchantRoleValue | null;
    /** All roles the user holds (system + custom). Phase 4.8+. */
    roles: string[];
    /** null = all branches; number[] = restricted to those ids. */
    branch_scope: number[] | null;
    last_login_at: string | null;
    invited_at: string | null;
    invited_by_admin_id: number | null;
    /** The user has a usable password. */
    password_set?: boolean;
    /** No usable password yet (new, or reset): waiting for their link. */
    setup_pending?: boolean;
    set_password_link_expires_at?: string | null;
    created_at: string | null;
}

export interface CreatePortalUserPayload {
    name: string;
    email: string;
    phone?: string | null;
    role: MerchantRoleValue;
    branch_scope?: number[] | null;
}

export interface UpdatePortalUserPayload {
    name?: string;
    phone?: string | null;
    role?: MerchantRoleValue;
    branch_scope?: number[] | null;
}

/**
 * Owner follow-up 2026-10-01 — a single-use set-password link, returned
 * ONCE on create / reset (never a password). Same shape as pos_admin's.
 */
export interface SetPasswordLink {
    url: string;
    /** ISO-8601. Invite links last 72 hours, reset links 60 minutes. */
    expires_at: string;
    purpose: 'invite' | 'reset';
    emailed: boolean;
    mail_configured: boolean;
    email_error: string | null;
}

export interface PortalUserWithLinkResponse {
    data: PortalUser;
    /** Shown once in the "Copy set-password link" dialog, then forgotten. */
    set_password_link: SetPasswordLink;
}

export function listPortalUsers(): Promise<{ data: PortalUser[] }> {
    return apiGet<{ data: PortalUser[] }>('/api/portal-users');
}

export function createPortalUser(
    payload: CreatePortalUserPayload,
): Promise<PortalUserWithLinkResponse> {
    return apiPost<PortalUserWithLinkResponse>(
        '/api/portal-users',
        payload as unknown as JsonValue,
    );
}

export function updatePortalUser(
    id: number,
    payload: UpdatePortalUserPayload,
): Promise<{ data: PortalUser }> {
    return apiPatch<{ data: PortalUser }>(
        `/api/portal-users/${id}`,
        payload as unknown as JsonValue,
    );
}

export function suspendPortalUser(id: number): Promise<{ data: PortalUser }> {
    return apiPost<{ data: PortalUser }>(`/api/portal-users/${id}/suspend`);
}

export function reactivatePortalUser(id: number): Promise<{ data: PortalUser }> {
    return apiPost<{ data: PortalUser }>(`/api/portal-users/${id}/reactivate`);
}

export function resetPortalUserPassword(
    id: number,
): Promise<PortalUserWithLinkResponse> {
    return apiPost<PortalUserWithLinkResponse>(
        `/api/portal-users/${id}/reset-password`,
    );
}

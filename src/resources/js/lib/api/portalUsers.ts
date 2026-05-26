/**
 * Typed client for the Portal Users endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Portal\PortalUsersController}.
 * All endpoints are auto-scoped server-side to the actor's
 * company — no merchant uuid in the URL.
 *
 * The create + reset-password responses carry a one-shot
 * `plaintext_password` field OUTSIDE the `data` envelope so the
 * frontend has to consciously handle it (vs accidentally storing
 * it alongside other user fields).
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
    role: MerchantRoleValue | null;
    /** null = all branches; number[] = restricted to those ids. */
    branch_scope: number[] | null;
    last_login_at: string | null;
    invited_at: string | null;
    invited_by_admin_id: number | null;
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

export interface PortalUserWithPasswordResponse {
    data: PortalUser;
    plaintext_password: string;
}

export function listPortalUsers(): Promise<{ data: PortalUser[] }> {
    return apiGet<{ data: PortalUser[] }>('/api/portal-users');
}

export function createPortalUser(
    payload: CreatePortalUserPayload,
): Promise<PortalUserWithPasswordResponse> {
    return apiPost<PortalUserWithPasswordResponse>(
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
): Promise<PortalUserWithPasswordResponse> {
    return apiPost<PortalUserWithPasswordResponse>(
        `/api/portal-users/${id}/reset-password`,
    );
}

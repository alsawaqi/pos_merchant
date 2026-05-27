/**
 * Typed client for the Roles + Permissions endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\RolesController}.
 * The catalog endpoint returns the canonical permission tree
 * (grouped by domain with EN/AR labels) for the role-editor's
 * checkbox grid. CRUD endpoints manage roles scoped to the
 * actor's company team.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export interface Role {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: string[];
    user_count: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface PermissionDescriptor {
    key: string;
    label_en: string;
    label_ar: string;
}

export interface PermissionGroup {
    key: string;
    label_en: string;
    label_ar: string;
    permissions: PermissionDescriptor[];
}

export interface CreateRolePayload {
    name: string;
    description?: string | null;
    permissions?: string[];
}

export interface UpdateRolePayload {
    name?: string;
    description?: string | null;
    permissions?: string[];
}

export function listRoles(): Promise<{ data: Role[] }> {
    return apiGet<{ data: Role[] }>('/api/roles');
}

export function getPermissionCatalog(): Promise<{ data: PermissionGroup[] }> {
    return apiGet<{ data: PermissionGroup[] }>('/api/roles/catalog');
}

export function createRole(payload: CreateRolePayload): Promise<{ data: Role }> {
    return apiPost<{ data: Role }>('/api/roles', payload as unknown as JsonValue);
}

export function updateRole(
    id: number,
    payload: UpdateRolePayload,
): Promise<{ data: Role }> {
    return apiPatch<{ data: Role }>(`/api/roles/${id}`, payload as unknown as JsonValue);
}

export function deleteRole(id: number): Promise<void> {
    return apiDelete<void>(`/api/roles/${id}`);
}

/** Replace a portal user's role list. */
export function assignRolesToPortalUser(
    userId: number,
    roleNames: string[],
): Promise<{ data: unknown }> {
    return apiPatch<{ data: unknown }>(
        `/api/portal-users/${userId}/roles`,
        { roles: roleNames } as unknown as JsonValue,
    );
}

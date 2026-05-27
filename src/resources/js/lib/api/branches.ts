/**
 * Branches API — TWO surfaces:
 *
 *   listBranches()           → lean shape from /api/branches.
 *                              Used by the Portal Users branch-
 *                              scope multi-select. No permission
 *                              gate server-side, every authed
 *                              merchant user can call it.
 *
 *   listMerchantBranches()   → full shape from /api/pos/branches.
 *   showMerchantBranch(uuid)   Gated by branches.view server-side.
 *   updateMerchantBranch(...)  branches.update gates the PATCH;
 *                              status transitions additionally
 *                              require branches.transition_status
 *                              (enforced inside the action layer,
 *                              surfaces as 403 with a message).
 */

import { apiGet, apiPatch, type JsonValue } from '@/lib/api';

export type BranchStatus = 'active' | 'inactive';
export type BranchOrderType =
    | 'quick'
    | 'dine_in'
    | 'to_go'
    | 'delivery'
    | 'car';

/** Lean shape returned by /api/branches (Portal Users picker). */
export interface Branch {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    code: string | null;
    status: BranchStatus | null;
}

/** Full shape returned by /api/pos/branches[/:uuid]. */
export interface MerchantBranch {
    id: number;
    uuid: string;
    code: string | null;
    name: string;
    name_ar: string | null;
    manager_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    country_id: number | null;
    region_id: number | null;
    district_id: number | null;
    city_id: number | null;
    latitude: string | null;
    longitude: string | null;
    geofence_radius_m: number | null;
    /**
     * Map of weekday key → schedule. Day keys typically
     * `mon|tue|wed|thu|fri|sat|sun`. Each value: {open, close, closed}.
     */
    opening_hours_json: Record<string, OpeningDay> | null;
    default_order_type: BranchOrderType | null;
    status: BranchStatus | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface OpeningDay {
    open?: string;
    close?: string;
    closed?: boolean;
}

export interface UpdateMerchantBranchPayload {
    name?: string;
    name_ar?: string | null;
    manager_name?: string | null;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
    latitude?: string | number | null;
    longitude?: string | number | null;
    geofence_radius_m?: number;
    opening_hours_json?: Record<string, OpeningDay> | null;
    default_order_type?: BranchOrderType;
    status?: BranchStatus;
}

export function listBranches(): Promise<{ data: Branch[] }> {
    return apiGet<{ data: Branch[] }>('/api/branches');
}

export function listMerchantBranches(): Promise<{ data: MerchantBranch[] }> {
    return apiGet<{ data: MerchantBranch[] }>('/api/pos/branches');
}

export function showMerchantBranch(
    uuid: string,
): Promise<{ data: MerchantBranch }> {
    return apiGet<{ data: MerchantBranch }>(`/api/pos/branches/${uuid}`);
}

export function updateMerchantBranch(
    uuid: string,
    payload: UpdateMerchantBranchPayload,
): Promise<{ data: MerchantBranch }> {
    return apiPatch<{ data: MerchantBranch }>(
        `/api/pos/branches/${uuid}`,
        payload as unknown as JsonValue,
    );
}

/**
 * Phase B (Additions §1.2) — void + comp reason code lists.
 *
 * Mirrors:
 *   - {@link \App\Http\Controllers\Pos\VoidReasonsController}
 *   - {@link \App\Http\Controllers\Pos\CompReasonsController}
 *
 * Both gate on orders.cancel (same lever as the cancellation policy
 * they ride with) and ship to the device in /device/config. `code`
 * is server-minted on create and immutable — voided orders / comps
 * snapshot it.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export interface VoidReason {
    id: number;
    uuid: string;
    code: string;
    name: string;
    name_ar: string | null;
    /** TRUE = food was made: voiding keeps ingredients consumed. */
    affects_inventory: boolean;
    requires_manager: boolean;
    is_active: boolean;
    sort_order: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface CompReason {
    id: number;
    uuid: string;
    code: string;
    name: string;
    name_ar: string | null;
    /** OMR cap for a single comp; null = no cap. String for precision. */
    max_amount: string | null;
    is_active: boolean;
    sort_order: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface SaveVoidReasonPayload {
    name?: string;
    name_ar?: string | null;
    affects_inventory?: boolean;
    requires_manager?: boolean;
    is_active?: boolean;
    sort_order?: number;
}

export interface SaveCompReasonPayload {
    name?: string;
    name_ar?: string | null;
    max_amount?: string | number | null;
    is_active?: boolean;
    sort_order?: number;
}

export function listVoidReasons(): Promise<{ data: VoidReason[] }> {
    return apiGet<{ data: VoidReason[] }>('/api/void-reasons');
}

export function createVoidReason(payload: SaveVoidReasonPayload): Promise<{ data: VoidReason }> {
    return apiPost<{ data: VoidReason }>('/api/void-reasons', payload as unknown as JsonValue);
}

export function updateVoidReason(uuid: string, payload: SaveVoidReasonPayload): Promise<{ data: VoidReason }> {
    return apiPatch<{ data: VoidReason }>(`/api/void-reasons/${uuid}`, payload as unknown as JsonValue);
}

export function deleteVoidReason(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/void-reasons/${uuid}`);
}

export function listCompReasons(): Promise<{ data: CompReason[] }> {
    return apiGet<{ data: CompReason[] }>('/api/comp-reasons');
}

export function createCompReason(payload: SaveCompReasonPayload): Promise<{ data: CompReason }> {
    return apiPost<{ data: CompReason }>('/api/comp-reasons', payload as unknown as JsonValue);
}

export function updateCompReason(uuid: string, payload: SaveCompReasonPayload): Promise<{ data: CompReason }> {
    return apiPatch<{ data: CompReason }>(`/api/comp-reasons/${uuid}`, payload as unknown as JsonValue);
}

export function deleteCompReason(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/comp-reasons/${uuid}`);
}

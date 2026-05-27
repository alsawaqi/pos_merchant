/**
 * Typed client for the POS Staff endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\PosStaffController}.
 * All endpoints auto-scoped server-side to the actor's company —
 * no merchant uuid in the URL.
 *
 * The create + reset-pin responses carry a one-shot
 * `plaintext_pin` field OUTSIDE the `data` envelope so the
 * frontend has to consciously surface it in the dedicated modal
 * (rather than silently storing it alongside other staff
 * fields).
 *
 * Route binding key is the staff uuid — never expose internal
 * ids in URLs.
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { StaffPositionValue, StaffStatusValue } from '@/lib/staff';

export interface PosStaff {
    id: number;
    uuid: string;
    name: string;
    phone: string | null;
    staff_code: string | null;
    position: StaffPositionValue | null;
    status: StaffStatusValue | null;
    branch: {
        id: number;
        /** Only present when the resource eager-loaded `branch`. */
        name?: string | null;
    };
    creator: {
        id: number | null;
        name?: string | null;
    };
    hired_at: string | null;
    terminated_at: string | null;
    last_login_at: string | null;
    created_at: string | null;
    deleted_at: string | null;
}

export interface CreatePosStaffPayload {
    name: string;
    branch_id: number;
    position: StaffPositionValue;
    phone?: string | null;
    staff_code?: string | null;
    hired_at?: string | null;
}

export interface UpdatePosStaffPayload {
    name?: string;
    branch_id?: number;
    position?: StaffPositionValue;
    phone?: string | null;
    staff_code?: string | null;
    hired_at?: string | null;
}

export interface PosStaffWithPinResponse {
    data: PosStaff;
    plaintext_pin: string;
}

export function listPosStaff(): Promise<{ data: PosStaff[] }> {
    return apiGet<{ data: PosStaff[] }>('/api/pos-staff');
}

export function createPosStaff(
    payload: CreatePosStaffPayload,
): Promise<PosStaffWithPinResponse> {
    return apiPost<PosStaffWithPinResponse>(
        '/api/pos-staff',
        payload as unknown as JsonValue,
    );
}

export function updatePosStaff(
    uuid: string,
    payload: UpdatePosStaffPayload,
): Promise<{ data: PosStaff }> {
    return apiPatch<{ data: PosStaff }>(
        `/api/pos-staff/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function suspendPosStaff(uuid: string): Promise<{ data: PosStaff }> {
    return apiPost<{ data: PosStaff }>(`/api/pos-staff/${uuid}/suspend`);
}

export function reactivatePosStaff(uuid: string): Promise<{ data: PosStaff }> {
    return apiPost<{ data: PosStaff }>(`/api/pos-staff/${uuid}/reactivate`);
}

export function terminatePosStaff(uuid: string): Promise<{ data: PosStaff }> {
    return apiPost<{ data: PosStaff }>(`/api/pos-staff/${uuid}/terminate`);
}

export function resetPosStaffPin(uuid: string): Promise<PosStaffWithPinResponse> {
    return apiPost<PosStaffWithPinResponse>(`/api/pos-staff/${uuid}/reset-pin`);
}

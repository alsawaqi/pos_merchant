/**
 * Floor Plan API — floors + tables.
 *
 * Floor routes are branch-nested for index + create; once a
 * floor has a uuid the update/delete URLs go flat. Same shape
 * for tables (floor-nested create, flat update/delete +
 * regenerate-qr).
 *
 * Mirrors {@link \App\Http\Controllers\Pos\FloorsController}
 * + {@link \App\Http\Controllers\Pos\TablesController}.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type FloorStatus = 'active' | 'inactive';
export type TableStatus = 'active' | 'inactive';
export type TableShape = 'round' | 'square' | 'rectangle' | 'oval' | 'counter';

export interface MerchantTable {
    id: number;
    uuid: string;
    floor_id: number;
    label: string;
    seats: number;
    min_party: number | null;
    max_party: number | null;
    shape: TableShape | null;
    notes: string | null;
    qr_token: string;
    status: TableStatus | null;
    display_order: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Floor {
    id: number;
    uuid: string;
    branch_id: number;
    name: string;
    name_ar: string | null;
    display_order: number;
    status: FloorStatus | null;
    tables_count: number;
    /** Present when the controller eager-loaded them. */
    tables: MerchantTable[];
    created_at: string | null;
    updated_at: string | null;
}

// ---- Floor payloads ---------------------------------------------

export interface CreateFloorPayload {
    name: string;
    name_ar?: string | null;
    display_order?: number;
}

export interface UpdateFloorPayload {
    name?: string;
    name_ar?: string | null;
    display_order?: number;
    status?: FloorStatus;
}

// ---- Table payloads ---------------------------------------------

export interface CreateTablePayload {
    label: string;
    seats?: number;
    min_party?: number | null;
    max_party?: number | null;
    shape?: TableShape;
    notes?: string | null;
    display_order?: number;
}

export interface UpdateTablePayload {
    label?: string;
    seats?: number;
    min_party?: number | null;
    max_party?: number | null;
    shape?: TableShape;
    notes?: string | null;
    status?: TableStatus;
    display_order?: number;
}

export interface RegenerateQrResponse {
    data: MerchantTable;
    qr_token: string;
}

// ---- Floors ------------------------------------------------------

export function listFloors(branchUuid: string): Promise<{ data: Floor[] }> {
    return apiGet<{ data: Floor[] }>(`/api/branches/${branchUuid}/floors`);
}

export function createFloor(
    branchUuid: string,
    payload: CreateFloorPayload,
): Promise<{ data: Floor }> {
    return apiPost<{ data: Floor }>(
        `/api/branches/${branchUuid}/floors`,
        payload as unknown as JsonValue,
    );
}

export function updateFloor(
    floorUuid: string,
    payload: UpdateFloorPayload,
): Promise<{ data: Floor }> {
    return apiPatch<{ data: Floor }>(
        `/api/floors/${floorUuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteFloor(floorUuid: string): Promise<void> {
    return apiDelete<void>(`/api/floors/${floorUuid}`);
}

// ---- Tables ------------------------------------------------------

export function createTable(
    floorUuid: string,
    payload: CreateTablePayload,
): Promise<{ data: MerchantTable }> {
    return apiPost<{ data: MerchantTable }>(
        `/api/floors/${floorUuid}/tables`,
        payload as unknown as JsonValue,
    );
}

export function updateTable(
    tableUuid: string,
    payload: UpdateTablePayload,
): Promise<{ data: MerchantTable }> {
    return apiPatch<{ data: MerchantTable }>(
        `/api/tables/${tableUuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteTable(tableUuid: string): Promise<void> {
    return apiDelete<void>(`/api/tables/${tableUuid}`);
}

export function regenerateTableQr(tableUuid: string): Promise<RegenerateQrResponse> {
    return apiPost<RegenerateQrResponse>(`/api/tables/${tableUuid}/regenerate-qr`);
}

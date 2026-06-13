/**
 * PD3a — physical items: things that CANNOT be eaten (cups, lids, boxes,
 * light bulbs, cleaning items). Created and managed on the Inventory
 * page; never part of the catalogue. Stock operations ride the existing
 * product-stock endpoints (lib/api/productStock.ts) — a physical item's
 * uuid IS valid there (the storage rows share the piece-counting
 * machinery).
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

/** 'packaging' = used with food (composition picker offers it); 'general' = branch use. */
export type PhysicalItemPurpose = 'packaging' | 'general';

export interface PhysicalItem {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    purpose: PhysicalItemPurpose;
    cost_price: string | null;
    low_stock_threshold: string | null;
    status: string | null;
    central_quantity: string;
}

export interface CreatePhysicalItemPayload {
    name: string;
    name_ar?: string | null;
    purpose: PhysicalItemPurpose;
    cost_price?: string | number | null;
    low_stock_threshold?: string | number | null;
}

export interface UpdatePhysicalItemPayload {
    name?: string;
    name_ar?: string | null;
    purpose?: PhysicalItemPurpose;
    cost_price?: string | number | null;
    low_stock_threshold?: string | number | null;
    status?: 'active' | 'inactive';
}

export function listPhysicalItems(): Promise<{ data: PhysicalItem[] }> {
    return apiGet<{ data: PhysicalItem[] }>('/api/physical-items');
}

export function createPhysicalItem(payload: CreatePhysicalItemPayload): Promise<{ data: PhysicalItem }> {
    return apiPost<{ data: PhysicalItem }>('/api/physical-items', payload as unknown as JsonValue);
}

export function updatePhysicalItem(uuid: string, payload: UpdatePhysicalItemPayload): Promise<{ data: PhysicalItem }> {
    return apiPatch<{ data: PhysicalItem }>(`/api/physical-items/${uuid}`, payload as unknown as JsonValue);
}

/** 422s while the item is still attached to a product's composition. */
export function deletePhysicalItem(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/physical-items/${uuid}`);
}

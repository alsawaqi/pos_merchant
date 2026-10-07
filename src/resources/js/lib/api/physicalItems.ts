/**
 * PD3a — physical items: things that CANNOT be eaten (cups, lids, boxes,
 * light bulbs, cleaning items). Created and managed on the Inventory
 * page; never part of the catalogue. Stock operations ride the existing
 * product-stock endpoints (lib/api/productStock.ts) — a physical item's
 * uuid IS valid there (the storage rows share the piece-counting
 * machinery).
 *
 * LAUNCH review add-on — A4 a SKU per item (blank = PHY-0001), D3 packs
 * ("box holds 50 cups", "carton holds 4 × box 50") and A5 barcodes per pack
 * and per piece.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { ItemBarcodeSummary } from '@/lib/api/inventory';

/** 'packaging' = used with food (composition picker offers it); 'general' = branch use. */
export type PhysicalItemPurpose = 'packaging' | 'general';

/** LAUNCH review add-on (D3) — a pack of a physical item. */
export interface PhysicalItemPack {
    uuid: string;
    name: string;
    name_ar: string | null;
    /** The item's pieces in ONE pack, nesting included. */
    pieces: string;
    contains_pack_uuid: string | null;
    contains_quantity: string | null;
    display_name: string;
    display_name_ar: string;
    /** True once used (a purchase line, or another pack holds it): the size cannot change. */
    size_locked: boolean | null;
    barcodes: ItemBarcodeSummary[];
}

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
    /** LAUNCH costs & allergens add-on — what it contains (ticked). */
    allergens?: string[];
    /** K-12 — its "may contain" ticks (never sent back by the Inventory modal). */
    may_contain?: string[];
    /** A4 — the supplier's code, or a generated PHY-0001. */
    sku?: string | null;
    packs?: PhysicalItemPack[];
    /** Barcodes on one piece (pack barcodes ride each pack). */
    barcodes?: ItemBarcodeSummary[];
}

export interface CreatePhysicalItemPayload {
    name: string;
    name_ar?: string | null;
    purpose: PhysicalItemPurpose;
    cost_price?: string | number | null;
    low_stock_threshold?: string | number | null;
    sku?: string | null;
}

export interface UpdatePhysicalItemPayload {
    name?: string;
    name_ar?: string | null;
    purpose?: PhysicalItemPurpose;
    cost_price?: string | number | null;
    low_stock_threshold?: string | number | null;
    status?: 'active' | 'inactive';
    sku?: string | null;
}

export interface PackPayload {
    name?: string;
    name_ar?: string | null;
    pieces?: string | number | null;
    contains_pack_uuid?: string | null;
    contains_quantity?: string | number | null;
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

/** LAUNCH review add-on (A4) — "Generate missing SKUs". */
export function generateMissingPhysicalSkus(): Promise<{ data: { generated: number } }> {
    return apiPost<{ data: { generated: number } }>('/api/physical-items/generate-skus');
}

export function listPacks(itemUuid: string): Promise<{ data: PhysicalItemPack[] }> {
    return apiGet<{ data: PhysicalItemPack[] }>(`/api/physical-items/${itemUuid}/packs`);
}

export function createPack(itemUuid: string, payload: PackPayload): Promise<{ data: PhysicalItemPack }> {
    return apiPost<{ data: PhysicalItemPack }>(`/api/physical-items/${itemUuid}/packs`, payload as unknown as JsonValue);
}

export function updatePack(itemUuid: string, packUuid: string, payload: PackPayload): Promise<{ data: PhysicalItemPack }> {
    return apiPatch<{ data: PhysicalItemPack }>(`/api/physical-items/${itemUuid}/packs/${packUuid}`, payload as unknown as JsonValue);
}

export function deletePack(itemUuid: string, packUuid: string): Promise<void> {
    return apiDelete<void>(`/api/physical-items/${itemUuid}/packs/${packUuid}`);
}

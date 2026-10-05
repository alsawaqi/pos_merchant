/**
 * LAUNCH review add-on (A5, D3, F) — barcodes and the scan box.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\InventoryCodesController}:
 *   POST   /api/inventory/barcodes          remember a barcode (inventory.manage)
 *   DELETE /api/inventory/barcodes/{uuid}   forget one
 *   GET    /api/inventory/scan?code=        what a code is (inventory.view)
 *   POST   /api/inventory/scan/link         link an unknown code, then look it up
 *
 * A barcode names an ingredient (optionally one of its containers) or a
 * physical item / bought-in product (optionally one of its packs). Kept as a
 * trimmed string: leading zeros stay.
 */

import { ApiError, apiDelete, apiGet, apiPost, type JsonValue } from '@/lib/api';
import type { ItemBarcodeSummary } from '@/lib/api/inventory';

export type ScanItemType = 'ingredient' | 'physical' | 'product';

export interface ScanResult {
    found: boolean;
    code?: string;
    /** Unknown code: whether this user may link it (inventory.manage). */
    can_link?: boolean;
    matched_by?: 'barcode' | 'product_barcode' | 'sku';
    item_type?: ScanItemType;
    item?: { uuid: string; name: string; name_ar: string | null; sku: string | null; unit?: string; is_prep?: boolean; stock_mode?: string };
    container?: { uuid: string; token: string; display_name: string; display_name_ar: string } | null;
    pack?: { uuid: string; pieces: string; display_name: string; display_name_ar: string } | null;
    label?: string | null;
    /** Fix order B-1 (L10) — false for a prep item or a product Purchases refuses. */
    purchasable?: boolean;
    not_purchasable_reason?: 'prep' | 'not_bought_in' | null;
}

/**
 * Fix order B-1 (M2) — a refused barcode save names the holding barcode row
 * (another live item has the code): its uuid and the message, or null.
 */
export function barcodeConflictOf(error: unknown): { holderUuid: string; message: string } | null {
    if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== 'object') return null;
    const payload = error.payload as { barcode_uuid?: unknown; message?: unknown };
    return typeof payload.barcode_uuid === 'string' && payload.barcode_uuid !== ''
        ? { holderUuid: payload.barcode_uuid, message: String(payload.message ?? '') }
        : null;
}

/** Fix order B-1 (M2) — take a barcode off the item that holds it and put it on this one. */
export async function moveBarcodeHere(holderUuid: string, barcode: string, payload: BarcodeLinkPayload): Promise<{ data: ItemBarcodeSummary }> {
    await deleteBarcode(holderUuid);
    return createBarcode(barcode, payload);
}

export interface BarcodeLinkPayload {
    item_type: ScanItemType;
    item_uuid: string;
    container_uuid?: string | null;
    pack_uuid?: string | null;
    label?: string | null;
}

export function scanCode(code: string): Promise<{ data: ScanResult }> {
    return apiGet<{ data: ScanResult }>(`/api/inventory/scan?code=${encodeURIComponent(code)}`);
}

export function linkScannedCode(code: string, payload: BarcodeLinkPayload): Promise<{ data: ScanResult }> {
    return apiPost<{ data: ScanResult }>('/api/inventory/scan/link', { code, ...payload } as unknown as JsonValue);
}

export function createBarcode(barcode: string, payload: BarcodeLinkPayload): Promise<{ data: ItemBarcodeSummary }> {
    return apiPost<{ data: ItemBarcodeSummary }>('/api/inventory/barcodes', { barcode, ...payload } as unknown as JsonValue);
}

export function deleteBarcode(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/inventory/barcodes/${uuid}`);
}

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

import { apiDelete, apiGet, apiPost, type JsonValue } from '@/lib/api';
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

/**
 * Purchase Receipts API — the PD6 Goods Received Note.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\PurchaseReceiptController}. One saved
 * document for a whole delivery: many mixed lines (ingredients + bought-in
 * products + physical items) each with a cost + optional inline branch split,
 * plus any number of named extra charges. Reading is inventory.view; recording
 * is inventory.manage + unrestricted branch scope (it credits the central
 * warehouse). Money + quantities are decimal:3 strings — keep them as strings.
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

// ---- Domain types -----------------------------------------------

export interface PurchaseReceiptLineAllocation {
    branch_id: number;
    branch_uuid: string;
    branch_name: string;
    quantity: string;
}

export interface PurchaseReceiptLine {
    item_type: 'ingredient' | 'product';
    item_name: string;
    quantity: string;
    unit: string | null;
    line_cost: string;
    expense_category: string | null;
    allocations: PurchaseReceiptLineAllocation[];
}

export interface PurchaseReceiptCharge {
    name: string;
    expense_category: string;
    amount: string;
}

export interface PurchaseReceipt {
    uuid: string;
    reference: string | null;
    status: string;
    note: string | null;
    items_total: string;
    charges_total: string;
    grand_total: string;
    received_at: string | null;
    supplier: { uuid: string; name: string } | null;
    recorded_by: string | null;
    /** Present in the list view (withCount). */
    lines_count?: number;
    /** Present in the detail view. */
    lines?: PurchaseReceiptLine[];
    charges?: PurchaseReceiptCharge[];
    created_at: string | null;
}

export interface PaginatedPurchaseReceipts {
    data: PurchaseReceipt[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

// ---- Submit payload ---------------------------------------------

export interface PurchaseReceiptLinePayload {
    item_type: 'ingredient' | 'product';
    item_uuid: string;
    quantity: string | number;
    line_cost: string | number;
    allocations?: Array<{ branch_uuid: string; quantity: string | number }>;
}

export interface PurchaseReceiptChargePayload {
    name: string;
    category: string;
    amount: string | number;
}

export interface CreatePurchaseReceiptPayload {
    supplier_uuid?: string | null;
    reference?: string | null;
    received_at?: string | null;
    note?: string | null;
    lines: PurchaseReceiptLinePayload[];
    charges?: PurchaseReceiptChargePayload[];
}

// ---- Endpoints --------------------------------------------------

export function listPurchaseReceipts(
    params: { page?: number; per_page?: number } = {},
): Promise<PaginatedPurchaseReceipts> {
    return apiGet<PaginatedPurchaseReceipts>('/api/purchase-receipts', {
        query: { page: params.page, per_page: params.per_page },
    });
}

export function getPurchaseReceipt(uuid: string): Promise<{ data: PurchaseReceipt }> {
    return apiGet<{ data: PurchaseReceipt }>(`/api/purchase-receipts/${uuid}`);
}

export function createPurchaseReceipt(
    payload: CreatePurchaseReceiptPayload,
): Promise<{ data: PurchaseReceipt }> {
    return apiPost<{ data: PurchaseReceipt }>(
        '/api/purchase-receipts',
        payload as unknown as JsonValue,
    );
}

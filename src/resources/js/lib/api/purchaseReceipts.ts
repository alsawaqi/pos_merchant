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
    /** LAUNCH review add-on — the share in pieces on a container line. */
    pieces?: string;
}

export interface PurchaseReceiptLine {
    item_type: 'ingredient' | 'product';
    item_name: string;
    quantity: string;
    unit: string | null;
    line_cost: string;
    /** PT — tax paid on this line (decimal:3 string); rate % or null. */
    tax_amount: string;
    tax_rate: string | null;
    expense_category: string | null;
    allocations: PurchaseReceiptLineAllocation[];
    /**
     * LAUNCH-P2 P2-3 — how the line was entered: the purchase unit (NULL =
     * base unit, '@piece' = the ingredient's piece unit), the quantity in it,
     * the price per it, and the cost per BASE unit the stock carries (6dp).
     */
    purchase_unit?: string | null;
    purchase_quantity?: string | null;
    unit_price?: string | null;
    unit_cost?: string | null;
    /** LAUNCH review add-on (C1, D3) — bought in a container / pack: how many, its label and size. */
    pieces?: string | null;
    container_label?: string | null;
    container_factor?: string | null;
}

export interface PurchaseReceiptCharge {
    name: string;
    expense_category: string;
    amount: string;
    tax_amount: string;
    tax_rate: string | null;
}

/** AP — 'paid' (settled at/after receive) | 'partial' | 'unpaid'. */
export type ReceiptPaymentStatus = 'paid' | 'partial' | 'unpaid';

export interface PurchaseReceiptPayment {
    uuid: string;
    amount: string;
    /** The outstanding balance LEFT after this payment. */
    balance_after: string;
    method: string | null;
    note: string | null;
    recorded_by: string | null;
    paid_at: string | null;
}

export interface PurchaseReceipt {
    uuid: string;
    reference: string | null;
    status: string;
    note: string | null;
    items_total: string;
    charges_total: string;
    /** PT — Σ line + charge tax. */
    tax_total: string;
    grand_total: string;
    /** AP — supplier credit + settlement. */
    is_credit: boolean;
    payment_status: ReceiptPaymentStatus;
    amount_paid: string;
    /** grand_total − amount_paid, never negative. */
    balance_due: string;
    due_date: string | null;
    received_at: string | null;
    supplier: { uuid: string; name: string } | null;
    /** Phase B — NULL = central warehouse; set = direct-to-branch delivery. */
    destination_branch?: { uuid: string; name: string } | null;
    recorded_by: string | null;
    /** Present in the list view (withCount). */
    lines_count?: number;
    /** Present in the detail view. */
    lines?: PurchaseReceiptLine[];
    charges?: PurchaseReceiptCharge[];
    /** AP — payment history, newest first (detail view). */
    payments?: PurchaseReceiptPayment[];
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
    /** In the line's `unit` (the base unit when omitted). Not sent on a container / pack line. */
    quantity?: string | number;
    /**
     * LAUNCH review add-on (C1, D3) — bought BY CONTAINER (an ingredient's
     * container_uuid, a physical item's pack_uuid): how many (pieces), and an
     * optional amount that may only be lowered (amount_unit = a kind unit; for
     * a pack, the item's pieces). The price paid is line_cost (required, 0 =
     * free); the split is in pieces.
     */
    container_uuid?: string | null;
    pack_uuid?: string | null;
    pieces?: string | number;
    amount?: string | number | null;
    amount_unit?: string | null;
    /**
     * LAUNCH-P2 P2-3 — the unit the quantity and split are in (NULL = base;
     * kg/g, l/ml, an extra unit's name or '@piece') and the price PER THAT
     * UNIT. With a unit price the server computes the line cost.
     */
    unit?: string | null;
    unit_price?: string | number | null;
    /** Required when no unit_price is sent. */
    line_cost?: string | number;
    /** PT — optional tax paid on the line (on top of line_cost). */
    tax_amount?: string | number | null;
    tax_rate?: string | number | null;
    /** The split: in the line's unit, or (review add-on) in pieces on a container line. */
    allocations?: Array<{ branch_uuid: string; quantity?: string | number; pieces?: string | number }>;
}

export interface PurchaseReceiptChargePayload {
    name: string;
    category: string;
    amount: string | number;
    tax_amount?: string | number | null;
    tax_rate?: string | number | null;
}

export interface CreatePurchaseReceiptPayload {
    supplier_uuid?: string | null;
    /**
     * Phase B — direct-to-branch delivery: the whole receipt lands at this
     * branch (every line auto-allocates 100% to it; per-line allocations
     * must be omitted). Absent/null = central warehouse.
     */
    destination_branch_uuid?: string | null;
    reference?: string | null;
    received_at?: string | null;
    note?: string | null;
    /** AP — was this delivery bought on credit (pay the supplier later)? */
    is_credit?: boolean;
    due_date?: string | null;
    lines: PurchaseReceiptLinePayload[];
    charges?: PurchaseReceiptChargePayload[];
}

export interface RecordReceiptPaymentPayload {
    amount: string | number;
    method?: string | null;
    note?: string | null;
    paid_at?: string | null;
}

// ---- Endpoints --------------------------------------------------

export function listPurchaseReceipts(
    params: { page?: number; per_page?: number; payment_status?: string } = {},
): Promise<PaginatedPurchaseReceipts> {
    return apiGet<PaginatedPurchaseReceipts>('/api/purchase-receipts', {
        query: { page: params.page, per_page: params.per_page, payment_status: params.payment_status },
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

/** AP — record a (full or partial) payment against a credit receipt. */
export function recordReceiptPayment(
    uuid: string,
    payload: RecordReceiptPaymentPayload,
): Promise<{ data: PurchaseReceipt }> {
    return apiPost<{ data: PurchaseReceipt }>(
        `/api/purchase-receipts/${uuid}/payments`,
        payload as unknown as JsonValue,
    );
}

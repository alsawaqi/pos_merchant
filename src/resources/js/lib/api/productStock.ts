/**
 * Product stock API — central pool + per-branch distribution for UNIT
 * (finished-good) products.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ProductStockController}. Gated
 * server-side: inventory.view for read, inventory.manage for every write; the
 * product must be in 'unit' stock mode. Quantities are decimal:3 strings — keep
 * them as strings, never parseFloat for stock counts.
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

// ---- Domain types -----------------------------------------------

export interface ProductStockBranch {
    branch_uuid: string;
    branch_name: string;
    /** Per-branch unit count; null = not unit-tracked at that branch yet. */
    stock_qty: string | null;
}

export interface ProductStockMovement {
    id: number;
    movement_type: string;
    quantity: string;
    branch_id: number | null;
    branch_name: string | null;
    note: string | null;
    recorded_by: string | null;
    occurred_at: string | null;
}

export interface ProductStockSummary {
    product_uuid: string;
    stock_mode: string;
    /** Central company pool balance. */
    central_quantity: string;
    branches: ProductStockBranch[];
    recent_movements: ProductStockMovement[];
}

export interface AllocationLine {
    branch_uuid: string;
    quantity: string | number;
}

// ---- Endpoints --------------------------------------------------

export function getProductStock(uuid: string): Promise<{ data: ProductStockSummary }> {
    return apiGet<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock`);
}

export function receiveProductStock(
    uuid: string,
    // PD2/PD5 — total_cost books a 'stock_purchases' (or 'physical_items')
    // expense, delivery_cost a 'delivery' one; required unless no_cost.
    payload: { quantity: string | number; total_cost?: string | number | null; delivery_cost?: string | number | null; no_cost?: boolean; tax_amount?: string | number | null; tax_rate?: string | number | null; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/receive`, payload as unknown as JsonValue);
}

export function allocateProductStock(
    uuid: string,
    payload: { allocations: AllocationLine[]; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/allocate`, payload as unknown as JsonValue);
}

/**
 * Receive a bulk quantity AND split it across branches in one submit. Anything
 * not distributed stays in the central pool. `allocations` may be empty.
 */
export function receiveAndDistributeProductStock(
    uuid: string,
    payload: { quantity: string | number; total_cost?: string | number | null; delivery_cost?: string | number | null; no_cost?: boolean; tax_amount?: string | number | null; tax_rate?: string | number | null; allocations: AllocationLine[]; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/receive-distribute`, payload as unknown as JsonValue);
}

export function transferProductStock(
    uuid: string,
    payload: { from_branch_uuid: string; to_branch_uuid: string; quantity: string | number; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/transfer`, payload as unknown as JsonValue);
}

export function adjustProductStock(
    uuid: string,
    payload: { branch_uuid?: string | null; signed_quantity: string | number; note: string },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/adjust`, payload as unknown as JsonValue);
}

/** Record wastage of a cooked or bought-in product at a branch (loss-tracking). */
export function recordProductWaste(
    uuid: string,
    payload: { branch_uuid: string; quantity: string | number; reason: string; notes?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/waste`, payload as unknown as JsonValue);
}

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
    payload: { quantity: string | number; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/receive`, payload as unknown as JsonValue);
}

export function allocateProductStock(
    uuid: string,
    payload: { allocations: AllocationLine[]; note?: string | null },
): Promise<{ data: ProductStockSummary }> {
    return apiPost<{ data: ProductStockSummary }>(`/api/products/${uuid}/stock/allocate`, payload as unknown as JsonValue);
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

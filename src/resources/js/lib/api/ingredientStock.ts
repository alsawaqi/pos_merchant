/**
 * Ingredient central-warehouse API — company pool + Receive & Distribute to
 * branches (P-G4), the ingredient twin of {@link ./productStock.ts}.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\IngredientStockController}. Gated
 * server-side: inventory.view for read, inventory.manage for every write.
 * Quantities are decimal:3 strings in the ingredient's BASE unit — keep them
 * as strings, never parseFloat for stock counts.
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

// ---- Domain types -----------------------------------------------

export interface IngredientStockBranch {
    branch_uuid: string;
    branch_name: string;
    /** Per-branch balance; null = this branch has never stocked it. */
    quantity: string | null;
}

export interface IngredientStockMovement {
    id: number;
    movement_type: string;
    quantity: string;
    branch_id: number | null;
    branch_name: string | null;
    note: string | null;
    recorded_by: string | null;
    occurred_at: string | null;
}

export interface IngredientStockSummary {
    ingredient_uuid: string;
    /** The ingredient's base unit (kg / g / l / ml / piece / pack / box). */
    unit: string;
    /** Central warehouse balance. */
    central_quantity: string;
    branches: IngredientStockBranch[];
    recent_movements: IngredientStockMovement[];
}

export interface IngredientAllocationLine {
    branch_uuid: string;
    quantity: string | number;
}

// ---- Endpoints --------------------------------------------------

export function getIngredientStock(uuid: string): Promise<{ data: IngredientStockSummary }> {
    return apiGet<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock`);
}

export function receiveIngredientStock(
    uuid: string,
    payload: { quantity: string | number; note?: string | null },
): Promise<{ data: IngredientStockSummary }> {
    return apiPost<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock/receive`, payload as unknown as JsonValue);
}

export function allocateIngredientStock(
    uuid: string,
    payload: { allocations: IngredientAllocationLine[]; note?: string | null },
): Promise<{ data: IngredientStockSummary }> {
    return apiPost<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock/allocate`, payload as unknown as JsonValue);
}

/**
 * Receive a bulk purchase AND split it across branches in one submit.
 * Anything not distributed stays in the warehouse. `allocations` may be empty.
 */
export function receiveAndDistributeIngredientStock(
    uuid: string,
    payload: { quantity: string | number; allocations: IngredientAllocationLine[]; note?: string | null },
): Promise<{ data: IngredientStockSummary }> {
    return apiPost<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock/receive-distribute`, payload as unknown as JsonValue);
}

/** Branch → branch move; lands as a regular BranchTransfer (Transfers tab). */
export function transferIngredientStock(
    uuid: string,
    payload: { from_branch_uuid: string; to_branch_uuid: string; quantity: string | number; note?: string | null },
): Promise<{ data: IngredientStockSummary }> {
    return apiPost<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock/transfer`, payload as unknown as JsonValue);
}

export function adjustIngredientStock(
    uuid: string,
    payload: { branch_uuid?: string | null; signed_quantity: string | number; note: string },
): Promise<{ data: IngredientStockSummary }> {
    return apiPost<{ data: IngredientStockSummary }>(`/api/ingredients/${uuid}/stock/adjust`, payload as unknown as JsonValue);
}

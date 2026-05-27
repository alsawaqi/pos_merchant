/**
 * Inventory API — ingredients, suppliers, per-branch stock,
 * adjust + restock, movement ledger.
 *
 * Mirrors:
 *   - {@link \App\Http\Controllers\Pos\IngredientsController}
 *   - {@link \App\Http\Controllers\Pos\SuppliersController}
 *   - {@link \App\Http\Controllers\Pos\StockController}
 *
 * Money + quantity columns come back as strings (Laravel
 * decimal:3 cast). Frontend treats them as opaque strings —
 * never parseFloat() because OMR-baisas precision matters.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type IngredientUnit = 'kg' | 'g' | 'l' | 'ml' | 'piece' | 'pack' | 'box';
export type InventoryStatus = 'active' | 'inactive';
export type StockHealthLevel = 'healthy' | 'low' | 'critical';

export type StockMovementType =
    | 'initial'
    | 'restock'
    | 'sale_consumption'
    | 'addon_consumption'
    | 'waste'
    | 'loss'
    | 'adjustment'
    | 'transfer_in'
    | 'transfer_out';

// ---- Domain types -----------------------------------------------

export interface Supplier {
    id: number;
    uuid: string;
    name: string;
    contact: string | null;
    notes: string | null;
    status: InventoryStatus;
    /** Present when the controller did withCount('ingredients'). */
    ingredients_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Ingredient {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    unit: IngredientUnit;
    /** OMR with 3-decimal precision — string for safety. */
    default_unit_cost: string;
    min_stock_threshold: string | null;
    primary_supplier_id: number | null;
    primary_supplier?: { id: number; uuid: string; name: string } | null;
    status: InventoryStatus;
    created_at: string | null;
    updated_at: string | null;
}

export interface BranchStockRow {
    id: number;
    branch_id: number;
    ingredient_id: number;
    quantity: string;
    last_movement_at: string | null;
    health_level: StockHealthLevel;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: IngredientUnit;
        default_unit_cost: string;
        min_stock_threshold: string | null;
    };
}

export interface StockMovement {
    id: number;
    branch_id: number;
    ingredient_id: number;
    movement_type: StockMovementType;
    quantity: string;
    unit_cost_at_time: string;
    reference_type: string | null;
    reference_id: number | null;
    note: string | null;
    occurred_at: string | null;
    recorded_by?: { id: number; name: string; kind: 'portal_user' } | null;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        unit: IngredientUnit;
    };
}

export interface PaginatedMovements {
    data: StockMovement[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

// ---- Payloads ---------------------------------------------------

export interface CreateIngredientPayload {
    name: string;
    name_ar?: string | null;
    unit: IngredientUnit;
    default_unit_cost?: string | number;
    min_stock_threshold?: string | number | null;
    primary_supplier_id?: number | null;
}

export interface UpdateIngredientPayload {
    name?: string;
    name_ar?: string | null;
    unit?: IngredientUnit;
    default_unit_cost?: string | number;
    min_stock_threshold?: string | number | null;
    primary_supplier_id?: number | null;
    status?: InventoryStatus;
}

export interface CreateSupplierPayload {
    name: string;
    contact?: string | null;
    notes?: string | null;
}

export interface UpdateSupplierPayload {
    name?: string;
    contact?: string | null;
    notes?: string | null;
    status?: InventoryStatus;
}

export interface AdjustStockPayload {
    ingredient_uuid: string;
    /** Signed — positive = found more, negative = found less. */
    signed_quantity: string | number;
    note: string;
}

export interface RestockPayload {
    ingredient_uuid: string;
    /** Positive only — server-enforced. */
    quantity: string | number;
    /** NULL = use ingredient default_unit_cost. */
    unit_cost?: string | number | null;
    supplier_uuid?: string | null;
    note?: string | null;
}

// ---- Ingredients ------------------------------------------------

export function listIngredients(): Promise<{ data: Ingredient[] }> {
    return apiGet<{ data: Ingredient[] }>('/api/ingredients');
}

export function createIngredient(payload: CreateIngredientPayload): Promise<{ data: Ingredient }> {
    return apiPost<{ data: Ingredient }>('/api/ingredients', payload as unknown as JsonValue);
}

export function updateIngredient(
    uuid: string,
    payload: UpdateIngredientPayload,
): Promise<{ data: Ingredient }> {
    return apiPatch<{ data: Ingredient }>(
        `/api/ingredients/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteIngredient(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/ingredients/${uuid}`);
}

// ---- Suppliers --------------------------------------------------

export function listSuppliers(): Promise<{ data: Supplier[] }> {
    return apiGet<{ data: Supplier[] }>('/api/suppliers');
}

export function createSupplier(payload: CreateSupplierPayload): Promise<{ data: Supplier }> {
    return apiPost<{ data: Supplier }>('/api/suppliers', payload as unknown as JsonValue);
}

export function updateSupplier(
    uuid: string,
    payload: UpdateSupplierPayload,
): Promise<{ data: Supplier }> {
    return apiPatch<{ data: Supplier }>(
        `/api/suppliers/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteSupplier(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/suppliers/${uuid}`);
}

// ---- Per-branch stock + movements ------------------------------

export function listBranchStock(branchUuid: string): Promise<{ data: BranchStockRow[] }> {
    return apiGet<{ data: BranchStockRow[] }>(`/api/branches/${branchUuid}/stock`);
}

export function adjustStock(
    branchUuid: string,
    payload: AdjustStockPayload,
): Promise<{ data: StockMovement }> {
    return apiPost<{ data: StockMovement }>(
        `/api/branches/${branchUuid}/stock/adjust`,
        payload as unknown as JsonValue,
    );
}

export function restockStock(
    branchUuid: string,
    payload: RestockPayload,
): Promise<{ data: StockMovement }> {
    return apiPost<{ data: StockMovement }>(
        `/api/branches/${branchUuid}/stock/restock`,
        payload as unknown as JsonValue,
    );
}

export function listStockMovements(
    branchUuid: string,
    filters?: { ingredient?: string; type?: StockMovementType; page?: number; per_page?: number },
): Promise<PaginatedMovements> {
    const params = new URLSearchParams();
    if (filters?.ingredient) params.set('ingredient', filters.ingredient);
    if (filters?.type) params.set('type', filters.type);
    if (filters?.page) params.set('page', String(filters.page));
    if (filters?.per_page) params.set('per_page', String(filters.per_page));
    const qs = params.toString();
    return apiGet<PaginatedMovements>(
        `/api/branches/${branchUuid}/stock-movements${qs ? `?${qs}` : ''}`,
    );
}

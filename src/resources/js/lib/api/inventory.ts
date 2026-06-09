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

/**
 * v2 #13 — an alternate unit for an ingredient. The ingredient's
 * existing `unit` field is the BASE unit (factor 1); each alt unit
 * declares how many base units equal one of itself via `factor`
 * (e.g. base "g", alt "kg" factor 1000). `factor` is a decimal
 * STRING (decimal(14,4), e.g. "1000.0000") — keep it opaque, never
 * parseFloat() for round-tripping. `name` is immutable after create.
 */
export interface IngredientAltUnit {
    id: number;
    uuid: string;
    ingredient_id: number;
    name: string;
    name_ar: string | null;
    factor: string;
    sort_order: number;
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
    /** v2 #13 — eager-loaded alternate units (GET /api/ingredients). */
    alt_units?: IngredientAltUnit[];
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
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
}

export interface RestockPayload {
    ingredient_uuid: string;
    /** Positive only — server-enforced. */
    quantity: string | number;
    /** NULL = use ingredient default_unit_cost. */
    unit_cost?: string | number | null;
    supplier_uuid?: string | null;
    note?: string | null;
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
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

// ---- Ingredient alternate units (v2 #13) -----------------------
//
// Per-ingredient sub-resource under the ingredient uuid. `factor`
// is decimal(14,4) server-side — send the user's raw string/number
// through so precision survives. `name` is set on create and is
// IMMUTABLE afterwards (PATCH ignores it). 422s: factor must be
// > 0, name can't equal the base unit, duplicate active names are
// rejected — surface them inline.

export interface CreateIngredientUnitPayload {
    name: string;
    name_ar?: string | null;
    factor: string | number;
    sort_order?: number;
}

export interface UpdateIngredientUnitPayload {
    /** name is IMMUTABLE — not sent on update. */
    name_ar?: string | null;
    factor?: string | number;
    sort_order?: number;
}

export function listIngredientUnits(
    ingredientUuid: string,
): Promise<{ data: IngredientAltUnit[] }> {
    return apiGet<{ data: IngredientAltUnit[] }>(
        `/api/ingredients/${ingredientUuid}/units`,
    );
}

export function createIngredientUnit(
    ingredientUuid: string,
    payload: CreateIngredientUnitPayload,
): Promise<{ data: IngredientAltUnit }> {
    return apiPost<{ data: IngredientAltUnit }>(
        `/api/ingredients/${ingredientUuid}/units`,
        payload as unknown as JsonValue,
    );
}

export function updateIngredientUnit(
    ingredientUuid: string,
    unitUuid: string,
    payload: UpdateIngredientUnitPayload,
): Promise<{ data: IngredientAltUnit }> {
    return apiPatch<{ data: IngredientAltUnit }>(
        `/api/ingredients/${ingredientUuid}/units/${unitUuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteIngredientUnit(
    ingredientUuid: string,
    unitUuid: string,
): Promise<void> {
    return apiDelete<void>(`/api/ingredients/${ingredientUuid}/units/${unitUuid}`);
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

// ============================================================
// Phase 5c — Waste records + Restock-request workflow
// ============================================================
//
// Both domains live under "inventory" — waste is a special
// signed-negative movement with reason taxonomy; restock
// requests are the branch→HQ replenishment workflow whose
// fulfilment writes positive stock movements at the requesting
// branch. Permissions live in lib/permissions.ts:
//   - InventoryManage gates RecordWaste (same trust class as
//     Adjustment — both mutate stock outside the sale path)
//   - RestockRequestCreate gates create/update/submit/cancel
//   - RestockRequestReview gates approve/reject/allocate

export type WasteReason =
    | 'expired'
    | 'spoiled'
    | 'broken'
    | 'dropped'
    | 'contamination'
    | 'other';

export type RestockRequestStatus =
    | 'draft'
    | 'submitted'
    | 'approved'
    | 'fulfilled'
    | 'rejected'
    | 'cancelled';

// ---- Domain types -----------------------------------------------

export interface WasteRecord {
    id: number;
    uuid: string;
    branch_id: number;
    ingredient_id: number;
    /** Always POSITIVE — the matching stock movement is negative. */
    quantity: string;
    reason: WasteReason;
    unit_at_set: IngredientUnit;
    /** Frozen at the moment of recording. */
    unit_cost_at_time: string;
    /** Pre-computed per-event cost (quantity × unit_cost_at_time). */
    total_cost: string;
    notes: string | null;
    occurred_at: string | null;
    created_at: string | null;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: IngredientUnit;
    };
    branch?: {
        id: number;
        uuid: string;
        name: string;
    };
    recorded_by?: { id: number; name: string } | null;
}

export interface PaginatedWaste {
    data: WasteRecord[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export interface RestockRequestLine {
    id: number;
    restock_request_id: number;
    ingredient_id: number;
    quantity_requested: string;
    quantity_allocated: string;
    unit_at_set: IngredientUnit;
    note: string | null;
    sort_order: number;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: IngredientUnit;
        default_unit_cost: string;
    } | null;
}

export interface RestockRequest {
    id: number;
    uuid: string;
    company_id: number;
    branch_id: number;
    status: RestockRequestStatus;
    /** Derived from RestockRequestStatus->isTerminal() server-side. */
    is_terminal: boolean;
    submitted_at: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    fulfilled_at: string | null;
    note: string | null;
    created_at: string | null;
    updated_at: string | null;
    branch?: { id: number; uuid: string; name: string };
    requested_by?: { id: number; name: string } | null;
    reviewed_by?: { id: number; name: string } | null;
    lines?: RestockRequestLine[];
    totals?: {
        line_count: number;
        quantity_requested: string;
        quantity_allocated: string;
        allocated_cost: string;
    };
}

// ---- Payloads ---------------------------------------------------

export interface RecordWastePayload {
    ingredient_uuid: string;
    /** Positive only — server enforces. */
    quantity: string | number;
    reason: WasteReason;
    notes?: string | null;
    /** ISO8601; defaults to now when omitted. */
    occurred_at?: string | null;
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
}

export interface RestockLinePayload {
    ingredient_uuid: string;
    quantity_requested: string | number;
    note?: string | null;
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
}

export interface CreateRestockRequestPayload {
    lines: RestockLinePayload[];
    note?: string | null;
}

export interface UpdateRestockRequestPayload {
    lines: RestockLinePayload[];
    note?: string | null;
}

export interface ReviewRestockRequestPayload {
    /** Required on reject (server enforces non-empty). */
    note?: string | null;
}

export interface CancelRestockRequestPayload {
    note?: string | null;
}

export interface AllocateRestockRequestPayload {
    /**
     * Optional per-line override, keyed by line.id. Omitting it
     * means "send the full requested amount of every line".
     * 0 is a legitimate value — skip that line.
     */
    allocations?: Record<number, string | number>;
}

// ---- Waste ------------------------------------------------------

export function listWaste(
    branchUuid: string,
    filters?: {
        ingredient?: string;
        reason?: WasteReason;
        from?: string;
        to?: string;
        page?: number;
        per_page?: number;
    },
): Promise<PaginatedWaste> {
    const params = new URLSearchParams();
    if (filters?.ingredient) params.set('ingredient', filters.ingredient);
    if (filters?.reason) params.set('reason', filters.reason);
    if (filters?.from) params.set('from', filters.from);
    if (filters?.to) params.set('to', filters.to);
    if (filters?.page) params.set('page', String(filters.page));
    if (filters?.per_page) params.set('per_page', String(filters.per_page));
    const qs = params.toString();
    return apiGet<PaginatedWaste>(
        `/api/branches/${branchUuid}/waste${qs ? `?${qs}` : ''}`,
    );
}

export function recordWaste(
    branchUuid: string,
    payload: RecordWastePayload,
): Promise<{ data: WasteRecord }> {
    return apiPost<{ data: WasteRecord }>(
        `/api/branches/${branchUuid}/waste`,
        payload as unknown as JsonValue,
    );
}

// ---- Restock requests ------------------------------------------

export function listRestockRequests(filters?: {
    status?: RestockRequestStatus;
    branch?: string;
}): Promise<{ data: RestockRequest[] }> {
    const params = new URLSearchParams();
    if (filters?.status) params.set('status', filters.status);
    if (filters?.branch) params.set('branch', filters.branch);
    const qs = params.toString();
    return apiGet<{ data: RestockRequest[] }>(
        `/api/restock-requests${qs ? `?${qs}` : ''}`,
    );
}

export function getRestockRequest(uuid: string): Promise<{ data: RestockRequest }> {
    return apiGet<{ data: RestockRequest }>(`/api/restock-requests/${uuid}`);
}

// ---- Smart restock suggestions ---------------------------------
//
// Read-only forecast: looks back over `window_days` of consumption
// at one branch, projects an `avg_daily_consumption`, and proposes
// a `suggested_quantity` to top each ingredient back up to a
// `target_level` covering `cover_days` of demand. Gated by
// inventory.view (read), so it's available to anyone who can see
// stock — turning the output into a real request is the separate
// inventory.restock_request.create gate (createRestockRequest).
//
// All quantities come back as decimal:3 STRINGS — keep them
// opaque, never parseFloat() for anything that gets sent back.

export type RestockSuggestionReason =
    | 'below_threshold_and_forecast'
    | 'below_threshold'
    | 'consumption_forecast';

export interface RestockSuggestion {
    ingredient_id: number;
    ingredient_uuid: string;
    name: string;
    unit: string;
    current_quantity: string;
    min_stock_threshold: string | null;
    consumed_in_window: string;
    avg_daily_consumption: string;
    target_level: string;
    suggested_quantity: string;
    reason: RestockSuggestionReason;
}

export interface RestockSuggestionsResponse {
    data: RestockSuggestion[];
    meta: {
        branch_id: number;
        branch_uuid: string;
        window_days: number;
        cover_days: number;
    };
}

/**
 * GET /api/branches/{branchUuid}/restock-suggestions.
 * `windowDays` / `coverDays` are each clamped server-side to
 * 1..365; defaults 30 / 14 when omitted.
 */
export function getRestockSuggestions(
    branchUuid: string,
    opts?: { windowDays?: number; coverDays?: number },
): Promise<RestockSuggestionsResponse> {
    const params = new URLSearchParams();
    if (opts?.windowDays !== undefined) params.set('window_days', String(opts.windowDays));
    if (opts?.coverDays !== undefined) params.set('cover_days', String(opts.coverDays));
    const qs = params.toString();
    return apiGet<RestockSuggestionsResponse>(
        `/api/branches/${branchUuid}/restock-suggestions${qs ? `?${qs}` : ''}`,
    );
}

export function createRestockRequest(
    branchUuid: string,
    payload: CreateRestockRequestPayload,
): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/branches/${branchUuid}/restock-requests`,
        payload as unknown as JsonValue,
    );
}

export function updateRestockRequest(
    uuid: string,
    payload: UpdateRestockRequestPayload,
): Promise<{ data: RestockRequest }> {
    return apiPatch<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function submitRestockRequest(uuid: string): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}/submit`,
        {} as JsonValue,
    );
}

export function approveRestockRequest(
    uuid: string,
    payload: ReviewRestockRequestPayload = {},
): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}/approve`,
        payload as unknown as JsonValue,
    );
}

export function rejectRestockRequest(
    uuid: string,
    payload: ReviewRestockRequestPayload,
): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}/reject`,
        payload as unknown as JsonValue,
    );
}

export function cancelRestockRequest(
    uuid: string,
    payload: CancelRestockRequestPayload = {},
): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}/cancel`,
        payload as unknown as JsonValue,
    );
}

export function allocateRestockRequest(
    uuid: string,
    payload: AllocateRestockRequestPayload = {},
): Promise<{ data: RestockRequest }> {
    return apiPost<{ data: RestockRequest }>(
        `/api/restock-requests/${uuid}/allocate`,
        payload as unknown as JsonValue,
    );
}

// ---- Branch→branch transfers (§5.6) ----------------------------
//
// Immediate, atomic ingredient move between two branches — no
// approval lifecycle (recording the transfer IS the move). The
// SOURCE branch is the create URL param; the destination + lines
// are in the body. The list is a FLAT { data: [...] } collection
// (NOT paginated — no .meta), mirroring listRestockRequests().

export interface BranchTransferLine {
    ingredient_id: number;
    ingredient_name: string | null;
    quantity: string;
    unit: string | null;
    unit_cost_at_time: string;
}

export interface BranchTransfer {
    id: number;
    uuid: string;
    from_branch_id: number;
    from_branch_name: string | null;
    to_branch_id: number;
    to_branch_name: string | null;
    transferred_at: string | null;
    note: string | null;
    lines: BranchTransferLine[];
    created_at: string | null;
}

export interface BranchTransferLinePayload {
    ingredient_uuid: string;
    quantity: string | number;
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
}

/** GET /api/branch-transfers (optional ?branch= filters EITHER side). */
export function listBranchTransfers(branchUuid?: string): Promise<{ data: BranchTransfer[] }> {
    const qs = branchUuid ? `?branch=${encodeURIComponent(branchUuid)}` : '';
    return apiGet<{ data: BranchTransfer[] }>(`/api/branch-transfers${qs}`);
}

/** POST /api/branches/{sourceBranchUuid}/transfers — the source branch is the URL param. */
export function createBranchTransfer(
    sourceBranchUuid: string,
    payload: { to_branch_uuid: string; note?: string | null; lines: BranchTransferLinePayload[] },
): Promise<{ data: BranchTransfer }> {
    return apiPost<{ data: BranchTransfer }>(
        `/api/branches/${sourceBranchUuid}/transfers`,
        payload as unknown as JsonValue,
    );
}

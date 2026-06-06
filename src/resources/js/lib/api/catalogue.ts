/**
 * Catalogue API — categories + products.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\CategoriesController}
 * + {@link \App\Http\Controllers\Pos\ProductsController}.
 *
 * Money columns come back as strings (Laravel decimal cast).
 * Frontend treats them as opaque strings — never parseFloat()
 * because OMR 3-decimal precision matters.
 */

import { apiDelete, apiGet, apiPatch, apiPost, apiPut, type JsonValue } from '@/lib/api';

export type CategoryStatus = 'active' | 'inactive';
export type ProductStatus = 'active' | 'inactive';
export type AddOnSelectionMode = 'single' | 'multi';
export type AddOnStatus = 'active' | 'inactive';

export interface Category {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    description: string | null;
    image_url: string | null;
    display_order: number;
    status: CategoryStatus | null;
    products_count: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Product {
    id: number;
    uuid: string;
    category_id: number | null;
    /** Present when the controller eager-loaded category. */
    category?: { id: number; uuid: string; name: string } | null;
    sku: string | null;
    barcode: string | null;
    name: string;
    name_ar: string | null;
    description: string | null;
    image_url: string | null;
    /** OMR with 3 decimals — keep as string for precision. */
    base_price: string;
    /**
     * Phase 4.9 — per-product delivery override. NULL means
     * "no delivery markup, use base_price". POS resolves this
     * via Product::priceFor() server-side.
     */
    delivery_price: string | null;
    /** Phase 7 — stock mode: unit | ingredient | untracked. */
    stock_mode: string | null;
    cost_price: string | null;
    /** Percentage (5.00 = 5%). null = inherit company default. */
    tax_rate: string | null;
    display_order: number;
    status: ProductStatus | null;
    /** Phase 4.9 — product-specific add-on groups when eager-loaded. */
    addon_groups?: AddOnGroup[];
    /**
     * Phase 5b — has at least one recipe line. Drives the
     * "Recipe" badge on the product list + decides whether
     * a sale will trigger inventory deduction (Phase 8).
     */
    has_recipe: boolean;
    /**
     * Phase 5b — Σ over recipe lines of (quantity × current
     * ingredient.default_unit_cost). String for precision.
     * "0.000" for products with no recipe. This is the
     * CURRENT cost (live ingredient prices) — Phase 8 orders
     * snapshot historical cost separately.
     */
    theoretical_cost: string;
    /** Phase 5b — recipe lines when eager-loaded by the controller. */
    recipe_lines?: ProductRecipeLine[];
    /** Phase B — per-branch availability + unit stock when eager-loaded. */
    branches?: ProductBranchAssignment[];
    created_at: string | null;
    updated_at: string | null;
}

// ---- Phase 5b — Recipe types -----------------------------------

export interface ProductRecipeLine {
    id: number;
    product_id: number;
    ingredient_id: number;
    /** decimal:3 string — never parseFloat. */
    quantity: string;
    /** Denormalised from ingredient at line-set time. */
    unit_at_set: string;
    sort_order: number;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: string;
        /** Current default cost — used by the live cost preview. */
        default_unit_cost: string;
    };
}

export interface RecipeLinePayload {
    ingredient_uuid: string;
    quantity: string | number;
}

export interface UpdateProductRecipePayload {
    lines: RecipeLinePayload[];
    note?: string | null;
}

// ---- Phase 4.9 — Add-ons ---------------------------------------

export interface AddOn {
    id: number;
    uuid: string;
    add_on_group_id: number;
    name: string;
    name_ar: string | null;
    /** OMR delta added to base price when selected. String for precision. */
    price_delta: string;
    display_order: number;
    status: AddOnStatus;
    created_at: string | null;
    updated_at: string | null;
}

export interface AddOnGroup {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    selection_mode: AddOnSelectionMode | null;
    is_global: boolean;
    display_order: number;
    status: AddOnStatus;
    products_count?: number;
    addons_count?: number;
    /** Inlined options when the controller eager-loaded them. */
    addons?: AddOn[];
    created_at: string | null;
    updated_at: string | null;
}

// ---- Category payloads ------------------------------------------

export interface CreateCategoryPayload {
    name: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    display_order?: number;
}

export interface UpdateCategoryPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    display_order?: number;
    status?: CategoryStatus;
}

// ---- Product payloads -------------------------------------------

export interface CreateProductPayload {
    name: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    category_id?: number | null;
    sku?: string | null;
    barcode?: string | null;
    base_price: string | number;
    /** Phase 4.9 — delivery-channel price override. */
    delivery_price?: string | number | null;
    /** Phase 7 — stock mode: unit (finished/piece-counted) | ingredient | untracked. */
    stock_mode?: 'unit' | 'ingredient' | 'untracked';
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    display_order?: number;
}

export interface UpdateProductPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    category_id?: number | null;
    sku?: string | null;
    barcode?: string | null;
    base_price?: string | number;
    delivery_price?: string | number | null;
    /** Phase 7 — stock mode: unit (finished/piece-counted) | ingredient | untracked. */
    stock_mode?: 'unit' | 'ingredient' | 'untracked';
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    display_order?: number;
    status?: ProductStatus;
}

// ---- Phase 4.9 — add-on payloads -------------------------------

export interface CreateAddOnGroupPayload {
    name: string;
    name_ar?: string | null;
    selection_mode?: AddOnSelectionMode;
    is_global?: boolean;
    display_order?: number;
}

export interface UpdateAddOnGroupPayload {
    name?: string;
    name_ar?: string | null;
    selection_mode?: AddOnSelectionMode;
    is_global?: boolean;
    display_order?: number;
    status?: AddOnStatus;
}

export interface CreateAddOnPayload {
    name: string;
    name_ar?: string | null;
    price_delta?: string | number;
    display_order?: number;
}

export interface UpdateAddOnPayload {
    name?: string;
    name_ar?: string | null;
    price_delta?: string | number;
    display_order?: number;
    status?: AddOnStatus;
}

// ---- Categories -------------------------------------------------

export function listCategories(): Promise<{ data: Category[] }> {
    return apiGet<{ data: Category[] }>('/api/categories');
}

export function createCategory(payload: CreateCategoryPayload): Promise<{ data: Category }> {
    return apiPost<{ data: Category }>('/api/categories', payload as unknown as JsonValue);
}

export function updateCategory(
    uuid: string,
    payload: UpdateCategoryPayload,
): Promise<{ data: Category }> {
    return apiPatch<{ data: Category }>(
        `/api/categories/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteCategory(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/categories/${uuid}`);
}

// ---- Products ---------------------------------------------------

export function listProducts(filters?: { category?: string }): Promise<{ data: Product[] }> {
    const qs = filters?.category ? `?category=${encodeURIComponent(filters.category)}` : '';
    return apiGet<{ data: Product[] }>(`/api/products${qs}`);
}

export function createProduct(payload: CreateProductPayload): Promise<{ data: Product }> {
    return apiPost<{ data: Product }>('/api/products', payload as unknown as JsonValue);
}

export function updateProduct(
    uuid: string,
    payload: UpdateProductPayload,
): Promise<{ data: Product }> {
    return apiPatch<{ data: Product }>(
        `/api/products/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteProduct(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/products/${uuid}`);
}

// ---- Phase 4.9 — Add-on Groups ---------------------------------

export function listAddOnGroups(): Promise<{ data: AddOnGroup[] }> {
    return apiGet<{ data: AddOnGroup[] }>('/api/addon-groups');
}

export function createAddOnGroup(payload: CreateAddOnGroupPayload): Promise<{ data: AddOnGroup }> {
    return apiPost<{ data: AddOnGroup }>('/api/addon-groups', payload as unknown as JsonValue);
}

export function updateAddOnGroup(
    uuid: string,
    payload: UpdateAddOnGroupPayload,
): Promise<{ data: AddOnGroup }> {
    return apiPatch<{ data: AddOnGroup }>(
        `/api/addon-groups/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteAddOnGroup(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/addon-groups/${uuid}`);
}

// ---- Phase 4.9 — Add-ons (within a group) ----------------------

export function createAddOn(
    groupUuid: string,
    payload: CreateAddOnPayload,
): Promise<{ data: AddOn }> {
    return apiPost<{ data: AddOn }>(
        `/api/addon-groups/${groupUuid}/addons`,
        payload as unknown as JsonValue,
    );
}

export function updateAddOn(
    addonUuid: string,
    payload: UpdateAddOnPayload,
): Promise<{ data: AddOn }> {
    return apiPatch<{ data: AddOn }>(
        `/api/addons/${addonUuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteAddOn(addonUuid: string): Promise<void> {
    return apiDelete<void>(`/api/addons/${addonUuid}`);
}

// ---- Phase 4.9 — Product ↔ Add-on Group sync -------------------

/**
 * Idempotent replace — POST the full desired set of group
 * uuids. Returns the post-sync group list eager-loaded.
 */
export function syncProductAddOnGroups(
    productUuid: string,
    groupUuids: string[],
): Promise<{ data: AddOnGroup[] }> {
    return apiPut<{ data: AddOnGroup[] }>(
        `/api/products/${productUuid}/addon-groups`,
        { group_uuids: groupUuids } as unknown as JsonValue,
    );
}

// ---- Phase 5b — Product Recipe ---------------------------------

/**
 * Idempotent replace of the full recipe. Empty array = "no
 * recipe / pre-made goods". Server snapshots the pre-edit
 * recipe to a version row + audits when the recipe actually
 * changes (no-op otherwise).
 */
export function updateProductRecipe(
    productUuid: string,
    payload: UpdateProductRecipePayload,
): Promise<{ data: Product }> {
    return apiPut<{ data: Product }>(
        `/api/products/${productUuid}/recipe`,
        payload as unknown as JsonValue,
    );
}

// ---- Phase B - product per-branch availability + stock ---------

export interface ProductBranchAssignment {
    branch_id: number;
    is_available: boolean;
    /** Per-branch units; null = not unit-tracked at that branch. */
    stock_qty: number | null;
}

/**
 * Idempotent replace of a product's per-branch availability + unit
 * stock. Empty array = available at every branch (device default).
 */
export function syncProductBranches(
    productUuid: string,
    branches: ProductBranchAssignment[],
): Promise<{ data: Product }> {
    return apiPut<{ data: Product }>(
        `/api/products/${productUuid}/branches`,
        { branches } as unknown as JsonValue,
    );
}

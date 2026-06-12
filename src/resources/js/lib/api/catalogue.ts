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
    /**
     * Phase D2 — §5.5.1 branch availability. null = all branches,
     * else the pos_branches ids that show this category.
     */
    branch_ids: number[] | null;
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
    /**
     * Phase D2 — unit-mode LOW STOCK badge threshold (decimal string).
     * null = no badge.
     */
    low_stock_threshold: string | null;
    cost_price: string | null;
    /** Percentage (5.00 = 5%). null = inherit company default. */
    tax_rate: string | null;
    /**
     * Phase D2 — §5.5.3 tax-inclusive flag. Display-only for now:
     * order totals still add company taxes on top (exclusive).
     */
    tax_inclusive: boolean;
    /** Phase D2 — §5.5.3 customer tablet visibility (POS ignores it). */
    show_on_customer_tablet: boolean;
    /**
     * G1 — menu time-window. 'HH:MM:SS' strings; both null = always
     * available; start > end wraps midnight (pos_discounts convention).
     */
    available_from: string | null;
    available_until: string | null;
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
    /**
     * v2 #13 — alt-unit NAME the quantity was entered in. null/omit
     * = the ingredient's base unit. Server converts to base.
     */
    unit?: string | null;
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
    /** Phase B — pre-selected in the POS customize sheet. */
    is_default: boolean;
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
    /**
     * Phase B — selection constraints. NULL = unbounded; min >= 1 makes
     * the group REQUIRED at the POS (add-to-cart blocked until satisfied).
     */
    min_selections: number | null;
    max_selections: number | null;
    /** Phase B — bound category ids (present when eager-loaded). */
    category_ids?: number[];
    is_global: boolean;
    /** v2 #6: non-null = a group privately owned by this product. */
    owner_product_id: number | null;
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
    /** Phase D2 — branch availability. [] / omitted = all branches. */
    branch_ids?: number[];
}

export interface UpdateCategoryPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    display_order?: number;
    status?: CategoryStatus;
    /** Phase D2 — branch availability. [] = back to all branches. */
    branch_ids?: number[];
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
    /** Phase 7 — stock mode: unit | ingredient | untracked | cooked (P-G1). */
    stock_mode?: 'unit' | 'ingredient' | 'untracked' | 'cooked';
    /** Phase D2 — unit-mode LOW STOCK badge threshold. null = no badge. */
    low_stock_threshold?: string | number | null;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    /** Phase D2 — §5.5.3 tax-inclusive flag (display-only for now). */
    tax_inclusive?: boolean;
    /** Phase D2 — §5.5.3 customer tablet visibility. */
    show_on_customer_tablet?: boolean;
    /** G1 — menu time-window ('HH:MM:SS', null = no bound). */
    available_from?: string | null;
    available_until?: string | null;
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
    /** Phase 7 — stock mode: unit | ingredient | untracked | cooked (P-G1). */
    stock_mode?: 'unit' | 'ingredient' | 'untracked' | 'cooked';
    /** Phase D2 — unit-mode LOW STOCK badge threshold. null = no badge. */
    low_stock_threshold?: string | number | null;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    /** Phase D2 — §5.5.3 tax-inclusive flag (display-only for now). */
    tax_inclusive?: boolean;
    /** Phase D2 — §5.5.3 customer tablet visibility. */
    show_on_customer_tablet?: boolean;
    /** G1 — menu time-window ('HH:MM:SS', null = no bound). */
    available_from?: string | null;
    available_until?: string | null;
    display_order?: number;
    status?: ProductStatus;
}

// ---- Phase 4.9 — add-on payloads -------------------------------

export interface CreateAddOnGroupPayload {
    name: string;
    name_ar?: string | null;
    selection_mode?: AddOnSelectionMode;
    min_selections?: number | null;
    max_selections?: number | null;
    category_ids?: number[];
    is_global?: boolean;
    display_order?: number;
}

export interface UpdateAddOnGroupPayload {
    name?: string;
    name_ar?: string | null;
    selection_mode?: AddOnSelectionMode;
    min_selections?: number | null;
    max_selections?: number | null;
    /** Full-list sync — send [] to unbind every category. */
    category_ids?: number[];
    is_global?: boolean;
    display_order?: number;
    status?: AddOnStatus;
}

export interface CreateAddOnPayload {
    name: string;
    name_ar?: string | null;
    price_delta?: string | number;
    is_default?: boolean;
    display_order?: number;
}

export interface UpdateAddOnPayload {
    name?: string;
    name_ar?: string | null;
    price_delta?: string | number;
    is_default?: boolean;
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

/** v2 #12 — standard Laravel resource-collection-over-paginator shape. */
export interface PaginatedProducts {
    data: Product[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export interface ListProductsParams {
    /** Case-insensitive LIKE across name + name_ar. */
    search?: string;
    /** Category UUID filter (unchanged behaviour). */
    category?: string;
    page?: number;
    /** Default 50 server-side, clamped 1–200. */
    per_page?: number;
}

export function listProducts(params: ListProductsParams = {}): Promise<PaginatedProducts> {
    return apiGet<PaginatedProducts>('/api/products', {
        query: {
            search: params.search,
            category: params.category,
            page: params.page,
            per_page: params.per_page,
        },
    });
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

// ---- v2 #6 — product-unique add-on groups (owned by one product) ----

export function getProductAddOnGroups(productUuid: string): Promise<{ data: AddOnGroup[] }> {
    return apiGet<{ data: AddOnGroup[] }>(`/api/products/${productUuid}/addon-groups`);
}

export function createProductAddOnGroup(
    productUuid: string,
    payload: CreateAddOnGroupPayload,
): Promise<{ data: AddOnGroup }> {
    return apiPost<{ data: AddOnGroup }>(
        `/api/products/${productUuid}/addon-groups`,
        payload as unknown as JsonValue,
    );
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

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

import { apiDelete, apiGet, apiPatch, apiPost, apiPut, apiUpload, type JsonValue } from '@/lib/api';

// ---- LAUNCH-P4 B5 — photo upload ---------------------------------

/**
 * Upload an already-resized photo; the answer is its public URL (on our own
 * host, under /storage/products/...), saved in image_url by the form.
 */
export function uploadCatalogueImage(photo: Blob, kind: 'product' | 'category'): Promise<{ data: { url: string; path: string } }> {
    const form = new FormData();
    form.append('image', photo, 'photo.jpg');
    form.append('kind', kind);
    return apiUpload<{ data: { url: string; path: string } }>('/api/catalogue/images', form);
}

export type CategoryStatus = 'active' | 'inactive';
export type ProductStatus = 'active' | 'inactive';
/** LAUNCH-P4 — a combo is a product with choice slots (B2). */
export type ProductType = 'standard' | 'combo';
/** LAUNCH-P4 H6 — where a product is sold. */
export type BranchScope = 'all' | 'selected';
export type AddOnSelectionMode = 'single' | 'multi';
export type AddOnStatus = 'active' | 'inactive';
/** LAUNCH review add-on — Extras (priced), a product's Remove list, Quick instructions. */
export type AddOnKind = 'extras' | 'remove' | 'instructions';

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
    /** LAUNCH-P4 L5 — the Arabic description. */
    description_ar?: string | null;
    image_url: string | null;
    /** LAUNCH-P4 B2 — 'standard' or 'combo'. */
    product_type?: ProductType;
    /** LAUNCH-P4 B3 — sold for in-store order types (till + handheld). */
    sold_in_store?: boolean;
    /** LAUNCH-P4 B3 — sold on delivery (each provider: listed + price). */
    sold_on_delivery?: boolean;
    /** LAUNCH-P4 H6 — 'all' branches, or only the 'selected' ones. */
    branch_scope?: BranchScope;
    /** LAUNCH-P4 B4 — branches (of the user's scope) where it is sold out. */
    sold_out_branch_ids?: number[];
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
    /** P-G1.5 — default shelf life in days. null = keeps indefinitely. */
    shelf_life_days: number | null;
    /** P-G2 — internal item: never on the POS menu or tablet. */
    is_internal: boolean;
    /** P-G2 — physical-item components per unit sold (when eager-loaded). */
    component_lines?: {
        component_uuid: string;
        component_name: string | null;
        quantity: string;
    }[];
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
    /** LAUNCH review add-on — limited-time dates ('YYYY-MM-DD', inclusive, Muscat; null = no bound). */
    on_sale_from?: string | null;
    on_sale_until?: string | null;
    /** LAUNCH review add-on — cooking time in minutes (null = not set). */
    cooking_minutes?: number | null;
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
    /**
     * LAUNCH-P3 P3-1 — how the line was typed ('g', 'box', '@piece' …) and
     * the quantity in that unit; null when it was typed in the base unit or
     * no longer converts to the stored base quantity.
     */
    entered_unit?: string | null;
    entered_quantity?: string | null;
    ingredient?: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: string;
        /** Current cost per base unit (a prep item: derived from its recipe). */
        default_unit_cost: string;
        /** LAUNCH-P3 P3-4 — a prep item (sauce, dough). */
        is_prep?: boolean;
        piece_unit_label?: string | null;
        piece_unit_label_ar?: string | null;
    };
}

// ---- LAUNCH-P3 P3-2 — recipe history ----------------------------

export interface RecipeLineChange {
    ingredient_id: number;
    ingredient: string;
    change: 'added' | 'removed' | 'changed';
    /** "150 g" — in the unit the line was entered in. */
    before: string | null;
    after: string | null;
    before_base: string | null;
    after_base: string | null;
}

export interface RecipeHistoryVersion {
    version: number;
    /** Prep items: the creation is 'created', every later change 'changed'. */
    event?: 'created' | 'changed';
    edited_at: string | null;
    edited_by: { id: number; name: string } | null;
    note: string | null;
    /** Prep items only. */
    yield_before?: string | null;
    yield_after?: string | null;
    changes: RecipeLineChange[];
}

export interface RecipeHistory {
    current: {
        version: number;
        prep_yield_quantity?: string;
        unit?: string | null;
        lines: { ingredient_id: number; ingredient: string; is_prep: boolean; amount: string; base: string }[];
    };
    /** Newest first. */
    versions: RecipeHistoryVersion[];
}

export function getProductRecipeHistory(productUuid: string): Promise<{ data: RecipeHistory }> {
    return apiGet<{ data: RecipeHistory }>(`/api/products/${productUuid}/recipe-history`);
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

// ---- P-G2 — physical-item components ---------------------------

export interface ComponentLinePayload {
    component_uuid: string;
    /** Per ONE unit sold (coffee = 1 x cup + 1 x lid). */
    quantity: string | number;
}

export interface ComponentOption {
    uuid: string;
    name: string;
    name_ar: string | null;
    is_internal: boolean;
    /** PD3b — 'unit' (packaging / bought-in) or 'cooked' (prepared). */
    stock_mode?: string | null;
}

// ---- PD3b — per-option stock-usage lines -------------------------

export type ConsumptionDirection = 'add' | 'remove';

/** A stored line as the API returns it (read shape). */
export interface AddOnConsumptionLine {
    type: 'ingredient' | 'product';
    direction: ConsumptionDirection;
    /** Ingredient lines: base-unit quantity. Product lines: pieces. */
    quantity: string;
    unit: string | null;
    /** LAUNCH-P3 P3-1 — ingredient lines: how they were typed (null = base). */
    entered_unit?: string | null;
    entered_quantity?: string | null;
    ingredient: { uuid: string; name: string; unit: string | null; is_prep?: boolean } | null;
    product: { uuid: string; name: string; stock_mode: string | null; is_internal: boolean } | null;
}

/** A line as the editor sends it (write shape). The *_label fields are
 * editor-only carry-overs from the read shape (so refs missing from the
 * picker lists still render) — completeConsumptionLines strips them. */
export interface ConsumptionLinePayload {
    type: 'ingredient' | 'product';
    ingredient_uuid?: string;
    product_uuid?: string;
    direction: ConsumptionDirection;
    quantity: string | number;
    unit?: string | null;
    ingredient_label?: string;
    product_label?: string;
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
    /** P-G3 — the real product behind this option (null = label-only). */
    linked_product_id: number | null;
    linked_product?: { uuid: string; name: string; stock_mode: string | null } | null;
    /** LAUNCH review add-on — an option of a Remove list: the ingredient it leaves out. */
    removes_ingredient_id?: number | null;
    /** PD3b — stock-usage lines (present when eager-loaded). */
    consumption?: AddOnConsumptionLine[];
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
    /** LAUNCH review add-on — 'extras' | 'remove' | 'instructions'. */
    kind?: AddOnKind;
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
    /** LAUNCH-P4 L5. */
    description_ar?: string | null;
    /** LAUNCH-P4 B3 — channels (the QR menu is show_on_customer_tablet). */
    sold_in_store?: boolean;
    sold_on_delivery?: boolean;
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
    /** P-G1.5 — default shelf life in days. null = keeps indefinitely. */
    shelf_life_days?: number | null;
    /** P-G2 — internal item: never on the POS menu or tablet. */
    is_internal?: boolean;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    /** Phase D2 — §5.5.3 tax-inclusive flag (display-only for now). */
    tax_inclusive?: boolean;
    /** Phase D2 — §5.5.3 customer tablet visibility. */
    show_on_customer_tablet?: boolean;
    /** G1 — menu time-window ('HH:MM:SS', null = no bound). */
    available_from?: string | null;
    available_until?: string | null;
    /** LAUNCH review add-on — limited-time dates and cooking time. */
    on_sale_from?: string | null;
    on_sale_until?: string | null;
    cooking_minutes?: number | null;
    display_order?: number;
}

export interface UpdateProductPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    /** LAUNCH-P4 L5. */
    description_ar?: string | null;
    /** LAUNCH-P4 B3 — channels. */
    sold_in_store?: boolean;
    sold_on_delivery?: boolean;
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
    /** P-G1.5 — default shelf life in days. null = keeps indefinitely. */
    shelf_life_days?: number | null;
    /** P-G2 — internal item: never on the POS menu or tablet. */
    is_internal?: boolean;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    /** Phase D2 — §5.5.3 tax-inclusive flag (display-only for now). */
    tax_inclusive?: boolean;
    /** Phase D2 — §5.5.3 customer tablet visibility. */
    show_on_customer_tablet?: boolean;
    /** G1 — menu time-window ('HH:MM:SS', null = no bound). */
    available_from?: string | null;
    available_until?: string | null;
    /** LAUNCH review add-on — limited-time dates and cooking time. */
    on_sale_from?: string | null;
    on_sale_until?: string | null;
    cooking_minutes?: number | null;
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
    /** LAUNCH review add-on — Extras or Quick instructions. */
    kind?: 'extras' | 'instructions';
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
    /** LAUNCH review add-on — Extras or Quick instructions. */
    kind?: 'extras' | 'instructions';
}

export interface CreateAddOnPayload {
    name: string;
    name_ar?: string | null;
    price_delta?: string | number;
    is_default?: boolean;
    /** P-G3 — the real product behind this option (null = label-only). */
    linked_product_uuid?: string | null;
    display_order?: number;
    /** PD3b — stock-usage lines created with the option. */
    consumption?: ConsumptionLinePayload[];
}

export interface UpdateAddOnPayload {
    name?: string;
    name_ar?: string | null;
    price_delta?: string | number;
    is_default?: boolean;
    /** P-G3 — link/unlink the real product behind this option. */
    linked_product_uuid?: string | null;
    display_order?: number;
    status?: AddOnStatus;
    /** PD3b — key present (even []) replaces the stock-usage lines. */
    consumption?: ConsumptionLinePayload[];
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
    /** LAUNCH-P4 B4 — only items sold out at one of the user's branches. */
    sold_out?: boolean;
}

export function listProducts(params: ListProductsParams = {}): Promise<PaginatedProducts> {
    return apiGet<PaginatedProducts>('/api/products', {
        query: {
            search: params.search,
            category: params.category,
            page: params.page,
            per_page: params.per_page,
            sold_out: params.sold_out ? 1 : undefined,
        },
    });
}

/** LAUNCH-P4 B4 — the answer of a sold-out switch. */
export interface SoldOutResult {
    product_uuid: string;
    branch_id: number;
    sold_out: boolean;
    /** Every branch (of the user's scope) where the item is now sold out. */
    sold_out_branch_ids: number[];
}

/** LAUNCH-P4 B4 — switch an item sold out (or back on sale) at one branch. */
export function setProductSoldOut(productUuid: string, branchId: number, soldOut: boolean): Promise<{ data: SoldOutResult }> {
    return apiPut<{ data: SoldOutResult }>(
        `/api/products/${productUuid}/sold-out`,
        { branch_id: branchId, sold_out: soldOut } as unknown as JsonValue,
    );
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

// ---- PD1 — the 3-step product wizard --------------------------

/** Inline option inside a wizard owned group (created with the product). */
export interface WizardOwnedOptionPayload {
    name: string;
    name_ar?: string | null;
    price_delta?: string | number;
    is_default?: boolean;
    linked_product_uuid?: string | null;
    display_order?: number;
    /** PD3b — stock-usage lines created with the option. */
    consumption?: ConsumptionLinePayload[];
}

/** Inline product-owned add-on group, created atomically with the product. */
export interface WizardOwnedGroupPayload {
    name: string;
    name_ar?: string | null;
    selection_mode?: AddOnSelectionMode;
    min_selections?: number | null;
    max_selections?: number | null;
    display_order?: number;
    options: WizardOwnedOptionPayload[];
}

/**
 * POST /api/products/wizard — ALL-OR-NOTHING create: the product plus
 * every composition section in one transaction. `branches: null` skips
 * the branch sync (available everywhere; required for scoped users).
 */
export interface CreateProductWizardPayload {
    product: CreateProductPayload;
    addon_group_uuids: string[];
    owned_groups: WizardOwnedGroupPayload[];
    recipe_lines: RecipeLinePayload[];
    recipe_note?: string | null;
    component_lines: ComponentLinePayload[];
    /** LAUNCH-P4 H6 — null = every branch (and the only value for scoped users). */
    branches: BranchScopePayload | null;
    /** LAUNCH-P4 B3 — per provider: listed + price (null = delivery price). */
    delivery_prices: ProviderChannelPayload[];
    /** LAUNCH review add-on — recipe lines ticked "Can be removed". */
    removable?: { ingredient_uuid: string; label: string | null; label_ar: string | null }[];
}

/** LAUNCH-P4 H6 — where the product is sold; never any shelf count (H7). */
export interface BranchScopePayload {
    branch_scope: BranchScope;
    branch_ids: number[];
}

/** LAUNCH-P4 B3 — one delivery provider row of a product. */
export interface ProviderChannelPayload {
    provider_uuid: string;
    listed: boolean;
    price: string | null;
}

export function createProductWizard(
    payload: CreateProductWizardPayload,
): Promise<{ data: Product }> {
    return apiPost<{ data: Product }>('/api/products/wizard', payload as unknown as JsonValue);
}

/** PD1 — single-product read for the wizard's edit mode (full prefill shape). */
export function getProduct(uuid: string): Promise<{ data: Product }> {
    return apiGet<{ data: Product }>(`/api/products/${uuid}`);
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

/**
 * P-G2 — idempotent full-replace of the product's physical-item
 * components. Empty lines = consumes no physical items. Components
 * must be unit-mode products of the same company (server-enforced).
 */
export function updateProductComponents(
    productUuid: string,
    lines: ComponentLinePayload[],
): Promise<{ data: Product }> {
    return apiPut<{ data: Product }>(
        `/api/products/${productUuid}/components`,
        { lines } as unknown as JsonValue,
    );
}

/** P-G2 — the slim picker source: unit-mode products, internal first. */
export function listComponentOptions(): Promise<{ data: ComponentOption[] }> {
    return apiGet<{ data: ComponentOption[] }>('/api/products/component-options');
}

// ---- P-G3 — product-as-add-on -----------------------------------

export interface AddonLinkOption {
    uuid: string;
    name: string;
    name_ar: string | null;
    stock_mode: string | null;
    /** LAUNCH-P4 B2 — the combo editor shows the item's own price. */
    base_price?: string;
    status?: ProductStatus | null;
    /** LAUNCH review add-on — the combo editor warns about limited-time items. */
    on_sale_from?: string | null;
    on_sale_until?: string | null;
    cooking_minutes?: number | null;
}

// ---- LAUNCH-P4 B2 — combos ---------------------------------------

export interface ComboSlotOption {
    product_uuid: string;
    product_name: string | null;
    product_name_ar: string | null;
    product_base_price: string | null;
    /** false = the item is deleted or switched off (still shown so it can be removed). */
    product_available: boolean;
    extra_price: string;
    is_default: boolean;
    sort_order: number;
}

export interface ComboSlot {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    min_choices: number;
    max_choices: number;
    sort_order: number;
    /** LAUNCH review add-on — offered as "Make it a meal?". */
    is_main?: boolean;
    options: ComboSlotOption[];
}

/** A combo as GET /api/combos/{uuid} returns it (a product + its slots). */
export type Combo = Product & {
    combo?: { slots: ComboSlot[] };
    delivery_provider_prices?: { price: string | null; listed: boolean; delivery_provider?: { uuid: string } | null }[];
};

export interface SaveComboPayload {
    name: string;
    name_ar: string | null;
    description: string | null;
    description_ar: string | null;
    image_url: string | null;
    category_id: number | null;
    sku: string | null;
    barcode: string | null;
    base_price: string;
    delivery_price: string | null;
    sold_in_store: boolean;
    show_on_customer_tablet: boolean;
    sold_on_delivery: boolean;
    available_from: string | null;
    available_until: string | null;
    /** LAUNCH review add-on — limited-time dates and the combo's own cooking time. */
    on_sale_from?: string | null;
    on_sale_until?: string | null;
    cooking_minutes?: number | null;
    display_order?: number;
    status?: ProductStatus;
    slots: {
        id?: number | null;
        name: string;
        name_ar: string | null;
        min_choices: number;
        max_choices: number;
        is_main?: boolean;
        options: { product_uuid: string; extra_price: string; is_default: boolean }[];
    }[];
    delivery_prices: ProviderChannelPayload[];
    branches: BranchScopePayload | null;
}

export function getCombo(uuid: string): Promise<{ data: Combo }> {
    return apiGet<{ data: Combo }>(`/api/combos/${uuid}`);
}

export function createCombo(payload: SaveComboPayload): Promise<{ data: Combo }> {
    return apiPost<{ data: Combo }>('/api/combos', payload as unknown as JsonValue);
}

export function updateCombo(uuid: string, payload: SaveComboPayload): Promise<{ data: Combo }> {
    return apiPut<{ data: Combo }>(`/api/combos/${uuid}`, payload as unknown as JsonValue);
}

/** P-G3 — the slim picker source: every sellable (non-internal) product. */
export function listAddonLinkOptions(): Promise<{ data: AddonLinkOption[] }> {
    return apiGet<{ data: AddonLinkOption[] }>('/api/products/addon-link-options');
}

// ---- Phase B - product per-branch availability + stock ---------

export interface ProductBranchAssignment {
    branch_id: number;
    is_available: boolean;
    /** Per-branch units; null = not unit-tracked at that branch. */
    stock_qty: number | null;
}

/**
 * LAUNCH-P4 H6 + H7 — which branches sell the product: every branch, or only
 * the selected ones. Never sends a shelf count (those change only through the
 * stock actions) and never removes a branch's stock row.
 */
export function syncProductBranches(
    productUuid: string,
    payload: BranchScopePayload,
): Promise<{ data: Product }> {
    return apiPut<{ data: Product }>(
        `/api/products/${productUuid}/branches`,
        payload as unknown as JsonValue,
    );
}

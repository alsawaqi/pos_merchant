<script setup lang="ts">
/**
 * Catalogue — manage menu categories + products. Phase 6.
 *
 * Tabbed page: Categories tab (list, create, edit, delete)
 * + Products tab (list with category filter, create, edit,
 * delete). Add-ons / modifiers / price lists land in
 * Phase 6c+ after orders are working.
 *
 * Permission gating:
 *   - Page reachable when CatalogueView
 *   - Create / edit / delete buttons only when CatalogueManage
 */

import { Beaker, Building2, Boxes, Clock3, Globe2, Image, Layers, Minus, Package, Pencil, Plus, Sparkles, Tag, Trash2, Truck } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import Pagination from '@/Components/Pagination.vue';
import ProductStockDialog from './ProductStockDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createAddOn,
    createAddOnGroup,
    createCategory,
    createProduct,
    createProductAddOnGroup,
    deleteAddOn,
    deleteAddOnGroup,
    deleteCategory,
    deleteProduct,
    getProductAddOnGroups,
    listAddOnGroups,
    listAddonLinkOptions,
    listCategories,
    listComponentOptions,
    listProducts,
    syncProductAddOnGroups,
    syncProductBranches,
    updateAddOn,
    updateAddOnGroup,
    updateCategory,
    updateProduct,
    updateProductComponents,
    updateProductRecipe,
    type AddOn,
    type AddOnGroup,
    type AddOnSelectionMode,
    type AddOnStatus,
    type AddonLinkOption,
    type Category,
    type CategoryStatus,
    type ComponentLinePayload,
    type ComponentOption,
    type CreateProductPayload,
    type Product,
    type ProductBranchAssignment,
    type ProductStatus,
    type RecipeLinePayload,
} from '@/lib/api/catalogue';
import { listIngredients, type Ingredient } from '@/lib/api/inventory';
import { listBranches, type Branch as BranchLite } from '@/lib/api/branches';
import {
    createDeliveryProvider,
    deleteDeliveryProvider,
    listDeliveryProviders,
    listProductDeliveryPrices,
    removeProductDeliveryPrice,
    setProductDeliveryPrice,
    updateDeliveryProvider,
    type DeliveryProvider,
    type ProductDeliveryPrice,
} from '@/lib/api/deliveryProviders';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.CatalogueManage));

type TabKey = 'categories' | 'products' | 'addons' | 'providers';
const activeTab = ref<TabKey>('categories');

// ---- Data --------------------------------------------------------
const categories = ref<Category[]>([]);
const products = ref<Product[]>([]);
// Phase 4.9 — every add-on group for the company, with its
// options eager-loaded. Drives both the Add-ons tab and the
// product modal's picker (filtered to non-global groups there).
const addOnGroups = ref<AddOnGroup[]>([]);
// Phase B - the company's branches, for the product editor's per-branch
// availability + stock picker. Lean list (id + name), no permission gate.
const branches = ref<BranchLite[]>([]);
// Phase 5b — ingredients power the product Recipe section's
// dropdown. Fetched from the inventory API; merchants without
// inventory.view get an empty list and a disabled recipe
// editor (which the UI explains via a hint).
const ingredients = ref<Ingredient[]>([]);
// P-G2 — the Physical items picker source (unit-mode products of the
// company, internal items first). Slim payload, fetched once.
const componentOptions = ref<ComponentOption[]>([]);
// P-G3 — the product-as-add-on picker source (sellable products).
const addonLinkOptions = ref<AddonLinkOption[]>([]);
// Phase 6c — delivery providers used by the Providers tab AND
// the per-product price grid in the product modal. We always
// fetch them so the product modal can render its price grid
// against the active list, not a stale snapshot.
const deliveryProviders = ref<DeliveryProvider[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// ---- Filters -----------------------------------------------------
const productCategoryFilter = ref<string>('');

// v2 #12 — product list is server-paginated + text-searchable. The
// `products` ref now holds only the CURRENT page, and productsMeta
// carries the paginator totals. search is debounced; changing the
// search text OR the category resets back to page 1.
const productSearch = ref<string>('');
const productPage = ref<number>(1);
const productsLoading = ref(false);
const productsMeta = ref<{ current_page: number; last_page: number; per_page: number; total: number }>({
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
});
let productSearchDebounce: ReturnType<typeof setTimeout> | null = null;

// Non-global add-on groups only — used by the product modal's
// multi-select picker. Global groups apply automatically and
// would be confusing to show as opt-in.
const selectableAddOnGroups = computed(() =>
    addOnGroups.value.filter((g) => !g.is_global),
);

// ---- Category modal ---------------------------------------------
const catModalOpen = ref(false);
const catModalBusy = ref(false);
const catModalMode = ref<'create' | 'edit'>('create');
const catModalTarget = ref<Category | null>(null);
const catModalErrors = ref<Record<string, string[]>>({});
const catModalError = ref<string | null>(null);
const catForm = reactive<{
    name: string;
    name_ar: string;
    description: string;
    image_url: string;
    display_order: number;
    status: CategoryStatus;
    // Phase D2 - branch availability. branch_all = show at every branch
    // (stored as NULL); otherwise the ticked branch ids.
    branch_all: boolean;
    branch_ids: number[];
}>({
    name: '',
    name_ar: '',
    description: '',
    image_url: '',
    display_order: 0,
    status: 'active',
    branch_all: true,
    branch_ids: [],
});

// ---- Product modal ----------------------------------------------
const prodModalOpen = ref(false);
const prodModalBusy = ref(false);
const prodModalMode = ref<'create' | 'edit'>('create');
const prodModalTarget = ref<Product | null>(null);
const prodModalErrors = ref<Record<string, string[]>>({});
const prodModalError = ref<string | null>(null);
const prodForm = reactive<{
    name: string;
    name_ar: string;
    description: string;
    image_url: string;
    category_id: number | null;
    sku: string;
    barcode: string;
    base_price: string;
    // Phase 4.9 — per-product delivery override (empty string
    // = NULL on submit). Server defaults to base_price when
    // null, so the cashier sees the same price for dine-in
    // and the merchant only fills this when delivery should
    // cost more.
    delivery_price: string;
    cost_price: string;
    tax_rate: string;
    // Phase D2 - §5.5.3 tax-inclusive flag. Display-only for now -
    // order totals still add company taxes on top (exclusive).
    tax_inclusive: boolean;
    // Phase D2 - §5.5.3 customer tablet visibility (POS unaffected).
    show_on_customer_tablet: boolean;
    // G1 — menu time-window. 'HH:mm' input values ('' = no bound; both
    // empty = always available). Converted to 'HH:MM:SS'/null on submit,
    // exactly like the Discounts time_start/time_end dance.
    available_from: string;
    available_until: string;
    display_order: number;
    status: ProductStatus;
    // Phase 7 — stock mode: unit (finished/piece-counted) | ingredient |
    // untracked | cooked (P-G1: recipe consumed at kitchen production).
    stock_mode: string;
    // Phase D2 - unit-mode LOW STOCK badge threshold ('' = no badge).
    low_stock_threshold: string;
    // P-G1.5 - default shelf life in days ('' = keeps indefinitely).
    shelf_life_days: string;
    // P-G2 - internal item (cups/lids): never on the POS menu or the
    // customer tablet, full stock participation.
    is_internal: boolean;
    // P-G2 - physical-item components per unit sold (coffee = 1 x cup +
    // 1 x lid). Empty on submit = consumes no physical items.
    component_rows: { component_uuid: string; quantity: string }[];
    // Phase 4.9 — uuids of non-global add-on groups attached
    // to this product. Mirrored from product.addon_groups on
    // edit, posted to syncProductAddOnGroups on save.
    addon_group_uuids: string[];
    // Phase 5b — recipe lines. Empty array on submit = "no
    // recipe / pre-made goods". Each row is mirrored to a
    // RecipeLinePayload at submitProduct time. `unit` (v2 #13) =
    // the alt-unit NAME the quantity was entered in, '' = base unit.
    recipe_lines: { ingredient_uuid: string; quantity: string; unit: string }[];
    // Phase B - per-branch availability + stock. branch_all = available
    // everywhere (no rows). Otherwise one row per company branch.
    branch_all: boolean;
    // stock_qty: '' when blank, a NUMBER once typed (number-input v-model).
    branch_rows: { branch_id: number; selected: boolean; stock_qty: string | number }[];
}>({
    name: '',
    name_ar: '',
    description: '',
    image_url: '',
    category_id: null,
    sku: '',
    barcode: '',
    base_price: '',
    delivery_price: '',
    cost_price: '',
    tax_rate: '',
    tax_inclusive: false,
    show_on_customer_tablet: true,
    available_from: '',
    available_until: '',
    display_order: 0,
    status: 'active',
    stock_mode: 'untracked',
    low_stock_threshold: '',
    shelf_life_days: '',
    is_internal: false,
    component_rows: [],
    addon_group_uuids: [],
    recipe_lines: [],
    branch_all: true,
    branch_rows: [],
});

// ---- Add-on group modal (Phase 4.9) -----------------------------
const agModalOpen = ref(false);
const agModalBusy = ref(false);
const agModalMode = ref<'create' | 'edit'>('create');
const agModalTarget = ref<AddOnGroup | null>(null);
const agModalErrors = ref<Record<string, string[]>>({});
const agModalError = ref<string | null>(null);
const agForm = reactive<{
    name: string;
    name_ar: string;
    selection_mode: AddOnSelectionMode;
    // Phase B — selection constraints ('' = unbounded) + category bindings.
    min_selections: string;
    max_selections: string;
    category_ids: number[];
    is_global: boolean;
    display_order: number;
    status: AddOnStatus;
}>({
    name: '',
    name_ar: '',
    selection_mode: 'single',
    min_selections: '',
    max_selections: '',
    category_ids: [],
    is_global: false,
    display_order: 0,
    status: 'active',
});

// ---- Add-on option modal (Phase 4.9) ----------------------------
// The "option" is a child of a group ("Whole milk" inside
// "Milk Choice"). aoModalParentGroup pins which group we're
// adding the option to / editing within.
const aoModalOpen = ref(false);
const aoModalBusy = ref(false);
const aoModalMode = ref<'create' | 'edit'>('create');
const aoModalParentGroup = ref<AddOnGroup | null>(null);
const aoModalTarget = ref<AddOn | null>(null);
const aoModalErrors = ref<Record<string, string[]>>({});
const aoModalError = ref<string | null>(null);
const aoForm = reactive<{
    name: string;
    name_ar: string;
    price_delta: string;
    is_default: boolean;
    // P-G3 — the real product behind this option ('' = label-only).
    linked_product_uuid: string;
    display_order: number;
    status: AddOnStatus;
}>({
    name: '',
    name_ar: '',
    is_default: false,
    linked_product_uuid: '',
    price_delta: '0',
    display_order: 0,
    status: 'active',
});

// ---- Delete confirms --------------------------------------------
const catDeleteTarget = ref<Category | null>(null);
const prodDeleteTarget = ref<Product | null>(null);

// Phase 7 — central stock dialog (unit products only).
const stockDialogProduct = ref<Product | null>(null);
function openStockDialog(product: Product): void {
    stockDialogProduct.value = product;
}
// Phase 4.9 — add-on delete confirms
const agDeleteTarget = ref<AddOnGroup | null>(null);
const aoDeleteTarget = ref<AddOn | null>(null);
const deleting = ref(false);

// ---- Fetchers ---------------------------------------------------

async function fetchCategories(): Promise<void> {
    try {
        const response = await listCategories();
        categories.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load categories';
    }
}

async function fetchProducts(): Promise<void> {
    productsLoading.value = true;
    try {
        const response = await listProducts({
            search: productSearch.value.trim() || undefined,
            category: productCategoryFilter.value === '' ? undefined : productCategoryFilter.value,
            page: productPage.value,
        });
        products.value = response.data;
        productsMeta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load products';
    } finally {
        productsLoading.value = false;
    }
}

// Reset to page 1 whenever the search text OR the category changes,
// then refetch. The category <select> drops its old @change handler
// in favour of this watcher. Search is debounced ~250ms.
watch(productSearch, () => {
    if (productSearchDebounce) clearTimeout(productSearchDebounce);
    productSearchDebounce = setTimeout(() => {
        productPage.value = 1;
        void fetchProducts();
    }, 250);
});

watch(productCategoryFilter, () => {
    productPage.value = 1;
    void fetchProducts();
});

function goProductPage(page: number): void {
    productPage.value = page;
    void fetchProducts();
}

async function fetchIngredients(): Promise<void> {
    // Soft-fail when the user lacks inventory.view — the recipe
    // editor degrades gracefully (Add Ingredient stays disabled,
    // hint asks them to visit Inventory first).
    try {
        const response = await listIngredients();
        ingredients.value = response.data;
    } catch {
        ingredients.value = [];
    }
}

async function fetchComponentOptions(): Promise<void> {
    // P-G2 - the Physical items picker source (unit-mode products,
    // internal first). Soft-fail: the section degrades to a hint.
    try {
        const response = await listComponentOptions();
        componentOptions.value = response.data;
    } catch {
        componentOptions.value = [];
    }
}

async function fetchAddonLinkOptions(): Promise<void> {
    // P-G3 - the product-as-add-on picker source. Soft-fail: the
    // picker degrades to "no products".
    try {
        const response = await listAddonLinkOptions();
        addonLinkOptions.value = response.data;
    } catch {
        addonLinkOptions.value = [];
    }
}

async function fetchAddOnGroups(): Promise<void> {
    try {
        const response = await listAddOnGroups();
        addOnGroups.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load add-on groups';
    }
}

async function fetchBranches(): Promise<void> {
    // Soft-fail: the picker degrades to "all branches" only.
    try {
        const response = await listBranches();
        branches.value = response.data;
    } catch {
        branches.value = [];
    }
}

async function fetchDeliveryProviders(): Promise<void> {
    try {
        const response = await listDeliveryProviders();
        deliveryProviders.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load delivery providers';
    }
}

async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    await Promise.all([
        fetchCategories(),
        fetchProducts(),
        fetchAddOnGroups(),
        fetchIngredients(),
        fetchComponentOptions(),
        fetchAddonLinkOptions(),
        fetchDeliveryProviders(),
        fetchBranches(),
    ]);
    loading.value = false;
}

onMounted(() => {
    void fetchAll();
});

// ---- Category flows ---------------------------------------------

function openCreateCategory(): void {
    catModalMode.value = 'create';
    catModalTarget.value = null;
    catForm.name = '';
    catForm.name_ar = '';
    catForm.description = '';
    catForm.image_url = '';
    catForm.display_order = categories.value.length;
    catForm.status = 'active';
    catForm.branch_all = true;
    catForm.branch_ids = [];
    catModalErrors.value = {};
    catModalError.value = null;
    catModalOpen.value = true;
}

function openEditCategory(category: Category): void {
    catModalMode.value = 'edit';
    catModalTarget.value = category;
    catForm.name = category.name;
    catForm.name_ar = category.name_ar ?? '';
    catForm.description = category.description ?? '';
    catForm.image_url = category.image_url ?? '';
    catForm.display_order = category.display_order;
    catForm.status = (category.status ?? 'active') as CategoryStatus;
    // Phase D2 - null/empty = all branches.
    catForm.branch_all = !category.branch_ids || category.branch_ids.length === 0;
    catForm.branch_ids = [...(category.branch_ids ?? [])];
    catModalErrors.value = {};
    catModalError.value = null;
    catModalOpen.value = true;
}

async function submitCategory(): Promise<void> {
    catModalBusy.value = true;
    catModalErrors.value = {};
    catModalError.value = null;
    try {
        const payload = {
            name: catForm.name.trim(),
            name_ar: catForm.name_ar.trim() || null,
            description: catForm.description.trim() || null,
            image_url: catForm.image_url.trim() || null,
            display_order: catForm.display_order,
            // Phase D2 - [] = all branches (server stores NULL).
            branch_ids: catForm.branch_all ? [] : catForm.branch_ids,
        };
        if (catModalMode.value === 'create') {
            await createCategory(payload);
        } else if (catModalTarget.value) {
            await updateCategory(catModalTarget.value.uuid, { ...payload, status: catForm.status });
        }
        catModalOpen.value = false;
        await fetchCategories();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            catModalErrors.value = err.payload.errors;
            catModalError.value = t('catalogue.validation_summary');
        } else {
            catModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        catModalBusy.value = false;
    }
}

async function confirmDeleteCategory(): Promise<void> {
    if (!catDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteCategory(catDeleteTarget.value.uuid);
        catDeleteTarget.value = null;
        await fetchCategories();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            error.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            error.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        deleting.value = false;
    }
}

// ---- Product flows ----------------------------------------------

function openCreateProduct(): void {
    prodModalMode.value = 'create';
    prodModalTarget.value = null;
    prodForm.name = '';
    prodForm.name_ar = '';
    prodForm.description = '';
    prodForm.image_url = '';
    prodForm.category_id = null;
    prodForm.sku = '';
    prodForm.barcode = '';
    prodForm.base_price = '';
    prodForm.delivery_price = '';
    prodForm.cost_price = '';
    prodForm.tax_rate = '';
    prodForm.tax_inclusive = false;
    prodForm.show_on_customer_tablet = true;
    prodForm.available_from = '';
    prodForm.available_until = '';
    // Default the new product's sort order to the end of the full
    // catalogue (total across pages, not just the current page).
    prodForm.display_order = productsMeta.value.total;
    prodForm.status = 'active';
    prodForm.stock_mode = 'untracked';
    prodForm.low_stock_threshold = '';
    prodForm.shelf_life_days = '';
    prodForm.is_internal = false;
    prodForm.component_rows = [];
    prodForm.addon_group_uuids = [];
    prodForm.recipe_lines = [];
    prodForm.branch_all = true;
    prodForm.branch_rows = buildBranchRows();
    prodModalErrors.value = {};
    prodModalError.value = null;
    prodModalOpen.value = true;
    // Phase 6c — clear the provider-price grid for the create flow.
    void loadProductProviderPrices(null);
    // v2 #6 — owned add-on groups need a saved product id, so
    // reset the editor; the template shows a "save first" hint.
    resetOwnedAddons();
}

function openEditProduct(product: Product): void {
    prodModalMode.value = 'edit';
    prodModalTarget.value = product;
    prodForm.name = product.name;
    prodForm.name_ar = product.name_ar ?? '';
    prodForm.description = product.description ?? '';
    prodForm.image_url = product.image_url ?? '';
    prodForm.category_id = product.category_id;
    prodForm.sku = product.sku ?? '';
    prodForm.barcode = product.barcode ?? '';
    prodForm.base_price = product.base_price;
    prodForm.delivery_price = product.delivery_price ?? '';
    prodForm.cost_price = product.cost_price ?? '';
    prodForm.tax_rate = product.tax_rate ?? '';
    prodForm.tax_inclusive = product.tax_inclusive ?? false;
    prodForm.show_on_customer_tablet = product.show_on_customer_tablet ?? true;
    // G1 — 'HH:MM:SS' on the wire → 'HH:mm' for <input type="time">.
    prodForm.available_from = product.available_from?.slice(0, 5) ?? '';
    prodForm.available_until = product.available_until?.slice(0, 5) ?? '';
    prodForm.display_order = product.display_order;
    prodForm.status = (product.status ?? 'active') as ProductStatus;
    prodForm.stock_mode = product.stock_mode ?? 'untracked';
    prodForm.low_stock_threshold = product.low_stock_threshold ?? '';
    prodForm.shelf_life_days = product.shelf_life_days !== null && product.shelf_life_days !== undefined
        ? String(product.shelf_life_days)
        : '';
    prodForm.is_internal = product.is_internal ?? false;
    // P-G2 - pre-populate the Physical items rows from the eager-loaded
    // component lines. Falls back to [] when none.
    prodForm.component_rows = (product.component_lines ?? []).map((line) => ({
        component_uuid: line.component_uuid,
        quantity: line.quantity,
    }));
    // Phase 4.9 — pre-populate the picker from the eager-
    // loaded relation. The list endpoint doesn't return
    // addon_groups, so editing relies on the resource emitting
    // them. Falls back to [] safely when undefined.
    prodForm.addon_group_uuids = (product.addon_groups ?? []).map((g) => g.uuid);
    // Phase 5b — pre-populate recipe lines from the eager-
    // loaded relation. Falls back to [] when none.
    // Stored recipe quantities are already in BASE units, so default
    // the entry unit to base ('') when preloading.
    prodForm.recipe_lines = (product.recipe_lines ?? []).map((line) => ({
        ingredient_uuid: line.ingredient?.uuid ?? '',
        quantity: line.quantity,
        unit: '',
    }));
    // Phase B - no assignments = available everywhere (branch_all on).
    prodForm.branch_all = (product.branches ?? []).length === 0;
    prodForm.branch_rows = buildBranchRows(product.branches);
    prodModalErrors.value = {};
    prodModalError.value = null;
    prodModalOpen.value = true;
    // Phase 6c — load existing provider prices for the grid.
    void loadProductProviderPrices(product.uuid);
    // v2 #6 — fetch this product's privately-owned add-on groups.
    resetOwnedAddons();
    void loadOwnedAddonGroups(product.uuid);
}

// ===================== Phase 5b — Recipe helpers =====================

function addRecipeLine(): void {
    // Default empty row — the merchant picks the ingredient
    // then enters a quantity. Validation at submit time.
    prodForm.recipe_lines.push({ ingredient_uuid: '', quantity: '', unit: '' });
}

function removeRecipeLine(idx: number): void {
    prodForm.recipe_lines.splice(idx, 1);
}

/**
 * Live theoretical-cost preview: Σ (quantity × ingredient.default_unit_cost)
 * across the current form's recipe lines. Returns a string with
 * 3-decimal precision. Used inline to show the merchant what
 * the recipe will cost without waiting for the server round-trip.
 *
 * Skips rows with missing ingredient / quantity (a half-edited
 * row contributes zero).
 */
const recipeLiveCost = computed<string>(() => {
    let total = 0;
    for (const line of prodForm.recipe_lines) {
        if (!line.ingredient_uuid || line.quantity === '') continue;
        const ingredient = ingredients.value.find((i) => i.uuid === line.ingredient_uuid);
        if (!ingredient) continue;
        // default_unit_cost is per BASE unit — convert the entered qty
        // (which may be in an alt unit) to base before multiplying.
        const qty = toBaseUnits(parseFloat(line.quantity), ingredient, line.unit);
        const cost = parseFloat(ingredient.default_unit_cost);
        if (!isFinite(qty) || !isFinite(cost)) continue;
        total += qty * cost;
    }
    return total.toFixed(3);
});

/**
 * Live margin preview: (base_price − recipe cost) / base_price
 * as a percentage. Returns null when base_price is 0 or blank
 * (division-by-zero) — UI hides the row in that case.
 */
const recipeLiveMargin = computed<string | null>(() => {
    const basePrice = parseFloat(prodForm.base_price);
    const cost = parseFloat(recipeLiveCost.value);
    if (!isFinite(basePrice) || basePrice <= 0) return null;
    if (!isFinite(cost)) return null;
    const margin = ((basePrice - cost) / basePrice) * 100;
    return margin.toFixed(1);
});

/**
 * True when the current recipe lines contain at least one
 * duplicate ingredient_uuid. Surfaces as an inline error +
 * disables the Save button (the server would 422 anyway).
 */
const recipeHasDuplicates = computed<boolean>(() => {
    const seen = new Set<string>();
    for (const line of prodForm.recipe_lines) {
        if (!line.ingredient_uuid) continue;
        if (seen.has(line.ingredient_uuid)) return true;
        seen.add(line.ingredient_uuid);
    }
    return false;
});

function ingredientUnitLabel(uuid: string): string {
    const ingredient = ingredients.value.find((i) => i.uuid === uuid);
    return ingredient?.unit ?? '';
}

/**
 * G1 — "06:00–11:00" row-badge label for a product with a menu
 * time-window. Null (no badge) when neither bound is set. A
 * single-sided window falls back to the day edge on the open
 * side, matching the device evaluator's defaults.
 */
function availabilityWindowLabel(prod: Product): string | null {
    if (!prod.available_from && !prod.available_until) return null;
    const from = prod.available_from?.slice(0, 5) ?? '00:00';
    const until = prod.available_until?.slice(0, 5) ?? '23:59';
    return `${from}–${until}`;
}

/** Resolve an ingredient (for its alt_units) from a recipe line uuid. */
function ingredientByUuid(uuid: string): Ingredient | null {
    if (!uuid) return null;
    return ingredients.value.find((i) => i.uuid === uuid) ?? null;
}

// v2 #13 — map a selected entry-unit string to the wire value:
// '' (base) → null, else the alt-unit name.
function wireUnit(selected: string): string | null {
    return selected.trim() === '' ? null : selected;
}

/**
 * Convert an entered quantity (number) to base units for the given
 * ingredient + selected alt-unit NAME ('' = base). Returns the raw
 * number unchanged when no matching alt unit is found. Local preview
 * only — never used to rebuild the value that gets sent.
 */
function toBaseUnits(qty: number, ingredient: Ingredient | null | undefined, selected: string): number {
    if (!ingredient || selected.trim() === '') return qty;
    const alt = (ingredient.alt_units ?? []).find((u) => u.name === selected);
    if (!alt) return qty;
    const factor = parseFloat(alt.factor);
    if (!Number.isFinite(factor)) return qty;
    return qty * factor;
}

// ---- Phase B - per-branch availability + stock helpers ----
function branchName(branchId: number): string {
    return branches.value.find((b) => b.id === branchId)?.name ?? `#${branchId}`;
}

function buildBranchRows(
    assignments?: ProductBranchAssignment[],
): { branch_id: number; selected: boolean; stock_qty: string | number }[] {
    const byBranch = new Map<number, ProductBranchAssignment>();
    for (const a of assignments ?? []) byBranch.set(a.branch_id, a);
    return branches.value.map((b) => {
        const a = byBranch.get(b.id);
        return {
            branch_id: b.id,
            selected: a ? a.is_available : false,
            stock_qty: a && a.stock_qty !== null ? String(a.stock_qty) : '',
        };
    });
}

async function submitProduct(): Promise<void> {
    prodModalBusy.value = true;
    prodModalErrors.value = {};
    prodModalError.value = null;
    try {
        const payload: CreateProductPayload = {
            name: prodForm.name.trim(),
            name_ar: prodForm.name_ar.trim() || null,
            description: prodForm.description.trim() || null,
            image_url: prodForm.image_url.trim() || null,
            category_id: prodForm.category_id ?? null,
            sku: prodForm.sku.trim() || null,
            barcode: prodForm.barcode.trim() || null,
            base_price: prodForm.base_price,
            // Phase 4.9 — empty string means NULL on the server
            // ("use base_price for delivery"). Otherwise pass
            // through as a string (decimal precision).
            delivery_price: prodForm.delivery_price === '' ? null : prodForm.delivery_price,
            cost_price: prodForm.cost_price === '' ? null : prodForm.cost_price,
            tax_rate: prodForm.tax_rate === '' ? null : prodForm.tax_rate,
            // Phase D2 - display-only flag (totals still add tax on top).
            tax_inclusive: prodForm.tax_inclusive,
            // Phase D2 - future customer tablet menu visibility.
            show_on_customer_tablet: prodForm.show_on_customer_tablet,
            // G1 — menu time-window: 'HH:mm' input → 'HH:MM:SS' on the
            // wire; empty = null = no bound (both empty = always).
            available_from: prodForm.available_from ? `${prodForm.available_from}:00` : null,
            available_until: prodForm.available_until ? `${prodForm.available_until}:00` : null,
            stock_mode: prodForm.stock_mode as 'unit' | 'ingredient' | 'untracked' | 'cooked',
            // Phase D2 - unit-mode LOW STOCK threshold ('' = none).
            low_stock_threshold: prodForm.low_stock_threshold === '' ? null : prodForm.low_stock_threshold,
            // P-G1.5 - default shelf life in days ('' = keeps indefinitely).
            shelf_life_days: prodForm.shelf_life_days === '' ? null : Number(prodForm.shelf_life_days),
            // P-G2 - internal item: never on the POS menu or tablet.
            is_internal: prodForm.is_internal,
            display_order: prodForm.display_order,
        };

        // Step 1: save the product itself.
        let productUuid: string;
        if (prodModalMode.value === 'create') {
            const created = await createProduct(payload);
            productUuid = created.data.uuid;
        } else if (prodModalTarget.value) {
            await updateProduct(prodModalTarget.value.uuid, { ...payload, status: prodForm.status });
            productUuid = prodModalTarget.value.uuid;
        } else {
            return; // shouldn't happen
        }

        // Step 2 (Phase 4.9): sync the product's add-on groups.
        // Idempotent — server does attach/detach diffing and
        // writes one audit row. Safe to call even when the
        // picker wasn't touched (empty change set = no-op).
        await syncProductAddOnGroups(productUuid, prodForm.addon_group_uuids);

        // Step 3 (Phase 5b): replace the product's recipe.
        // Skip empty / incomplete rows on the client so a
        // half-edited row doesn't trip the server validator.
        // Server-side is still the source of truth — also no-op
        // safe (returns 200 with no audit when recipe matches
        // disk).
        const cleanLines: RecipeLinePayload[] = prodForm.recipe_lines
            .filter((l) => l.ingredient_uuid && l.quantity !== '')
            .map((l) => ({
                ingredient_uuid: l.ingredient_uuid,
                quantity: l.quantity,
                unit: wireUnit(l.unit),
            }));
        await updateProductRecipe(productUuid, { lines: cleanLines });

        // Step 3a (P-G2): replace the product's physical-item
        // components. Same idempotent full-replace semantics as the
        // recipe; skip empty / incomplete rows client-side.
        const cleanComponents: ComponentLinePayload[] = prodForm.component_rows
            .filter((l) => l.component_uuid && l.quantity !== '')
            .map((l) => ({
                component_uuid: l.component_uuid,
                quantity: l.quantity,
            }));
        await updateProductComponents(productUuid, cleanComponents);

        // Step 3b (Phase B): replace per-branch availability + unit
        // stock. branch_all = clear all rows (available everywhere);
        // otherwise the ticked branches with their optional units.
        const branchPayload: ProductBranchAssignment[] = prodForm.branch_all
            ? []
            : prodForm.branch_rows
                .filter((r) => r.selected)
                .map((r) => ({
                    branch_id: r.branch_id,
                    is_available: true,
                    // v-model on a type="number" input stores a NUMBER once a
                    // value is typed (Vue auto-casts) and '' when cleared —
                    // coerce before trimming or submit crashes.
                    stock_qty: String(r.stock_qty ?? '').trim() === '' ? null : Number(r.stock_qty),
                }));
        await syncProductBranches(productUuid, branchPayload);

        // Step 4 (Phase 6c): sync per-provider price overrides.
        // Iterates the touched provider prices and fires
        // PUT (set) or DELETE (remove) per changed row. Same
        // idempotent semantics on the server.
        await syncProductProviderPrices(productUuid);

        prodModalOpen.value = false;
        await fetchProducts();
        // P-G2 - a newly saved unit product may now be a valid component.
        void fetchComponentOptions();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            prodModalErrors.value = err.payload.errors;
            prodModalError.value = t('catalogue.validation_summary');
        } else {
            prodModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        prodModalBusy.value = false;
    }
}

async function confirmDeleteProduct(): Promise<void> {
    if (!prodDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteProduct(prodDeleteTarget.value.uuid);
        prodDeleteTarget.value = null;
        await fetchProducts();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed';
    } finally {
        deleting.value = false;
    }
}

// =================== ADD-ON GROUP FLOWS (Phase 4.9) ====================

function openCreateAddOnGroup(): void {
    agModalMode.value = 'create';
    agModalTarget.value = null;
    agForm.name = '';
    agForm.name_ar = '';
    agForm.selection_mode = 'single';
    agForm.min_selections = '';
    agForm.max_selections = '';
    agForm.category_ids = [];
    agForm.is_global = false;
    agForm.display_order = addOnGroups.value.length;
    agForm.status = 'active';
    agModalErrors.value = {};
    agModalError.value = null;
    agModalOpen.value = true;
}

function openEditAddOnGroup(group: AddOnGroup): void {
    agModalMode.value = 'edit';
    agModalTarget.value = group;
    agForm.name = group.name;
    agForm.name_ar = group.name_ar ?? '';
    agForm.selection_mode = (group.selection_mode ?? 'single') as AddOnSelectionMode;
    agForm.min_selections = group.min_selections !== null ? String(group.min_selections) : '';
    agForm.max_selections = group.max_selections !== null ? String(group.max_selections) : '';
    agForm.category_ids = [...(group.category_ids ?? [])];
    agForm.is_global = group.is_global;
    agForm.display_order = group.display_order;
    agForm.status = (group.status ?? 'active') as AddOnStatus;
    agModalErrors.value = {};
    agModalError.value = null;
    agModalOpen.value = true;
}

async function submitAddOnGroup(): Promise<void> {
    agModalBusy.value = true;
    agModalErrors.value = {};
    agModalError.value = null;
    try {
        const payload = {
            name: agForm.name.trim(),
            name_ar: agForm.name_ar.trim() || null,
            selection_mode: agForm.selection_mode,
            // Phase B — '' = unbounded → null on the wire.
            min_selections: String(agForm.min_selections).trim() === '' ? null : Number(agForm.min_selections),
            max_selections: String(agForm.max_selections).trim() === '' ? null : Number(agForm.max_selections),
            category_ids: agForm.category_ids,
            is_global: agForm.is_global,
            display_order: agForm.display_order,
        };
        if (agModalMode.value === 'create') {
            await createAddOnGroup(payload);
        } else if (agModalTarget.value) {
            await updateAddOnGroup(agModalTarget.value.uuid, { ...payload, status: agForm.status });
        }
        agModalOpen.value = false;
        await fetchAddOnGroups();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            agModalErrors.value = err.payload.errors;
            agModalError.value = t('catalogue.validation_summary');
        } else {
            agModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        agModalBusy.value = false;
    }
}

async function confirmDeleteAddOnGroup(): Promise<void> {
    if (!agDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteAddOnGroup(agDeleteTarget.value.uuid);
        agDeleteTarget.value = null;
        await fetchAddOnGroups();
    } catch (err) {
        // 422 surfaces the "attached to N products" guard from
        // the server-side DeleteAddOnGroupAction — show it to
        // the merchant verbatim so they know what to do.
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            error.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            error.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        deleting.value = false;
    }
}

// =================== ADD-ON OPTION FLOWS (Phase 4.9) ===================

function openCreateAddOn(group: AddOnGroup): void {
    aoModalMode.value = 'create';
    aoModalParentGroup.value = group;
    aoModalTarget.value = null;
    aoForm.name = '';
    aoForm.name_ar = '';
    aoForm.price_delta = '0';
    aoForm.is_default = false;
    aoForm.linked_product_uuid = '';
    aoForm.display_order = (group.addons ?? []).length;
    aoForm.status = 'active';
    aoModalErrors.value = {};
    aoModalError.value = null;
    aoModalOpen.value = true;
}

function openEditAddOn(group: AddOnGroup, addon: AddOn): void {
    aoModalMode.value = 'edit';
    aoModalParentGroup.value = group;
    aoModalTarget.value = addon;
    aoForm.name = addon.name;
    aoForm.name_ar = addon.name_ar ?? '';
    aoForm.price_delta = addon.price_delta;
    aoForm.is_default = addon.is_default;
    aoForm.linked_product_uuid = addon.linked_product?.uuid ?? '';
    aoForm.display_order = addon.display_order;
    aoForm.status = (addon.status ?? 'active') as AddOnStatus;
    aoModalErrors.value = {};
    aoModalError.value = null;
    aoModalOpen.value = true;
}

// P-G3 — picking a product prefills the option name (still editable).
function onLinkedProductPicked(): void {
    const pick = addonLinkOptions.value.find((p) => p.uuid === aoForm.linked_product_uuid);
    if (pick && aoForm.name.trim() === '') {
        aoForm.name = pick.name;
        aoForm.name_ar = pick.name_ar ?? '';
    }
}

async function submitAddOn(): Promise<void> {
    aoModalBusy.value = true;
    aoModalErrors.value = {};
    aoModalError.value = null;
    try {
        const payload = {
            name: aoForm.name.trim(),
            name_ar: aoForm.name_ar.trim() || null,
            price_delta: aoForm.price_delta,
            is_default: aoForm.is_default,
            // P-G3 — the real product behind this option ('' = none).
            linked_product_uuid: aoForm.linked_product_uuid || null,
            display_order: aoForm.display_order,
        };
        if (aoModalMode.value === 'create' && aoModalParentGroup.value) {
            await createAddOn(aoModalParentGroup.value.uuid, payload);
        } else if (aoModalTarget.value) {
            await updateAddOn(aoModalTarget.value.uuid, { ...payload, status: aoForm.status });
        }
        aoModalOpen.value = false;
        await fetchAddOnGroups();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            aoModalErrors.value = err.payload.errors;
            aoModalError.value = t('catalogue.validation_summary');
        } else {
            aoModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        aoModalBusy.value = false;
    }
}

async function confirmDeleteAddOn(): Promise<void> {
    if (!aoDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteAddOn(aoDeleteTarget.value.uuid);
        aoDeleteTarget.value = null;
        await fetchAddOnGroups();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed';
    } finally {
        deleting.value = false;
    }
}

function selectionModeLabel(mode: AddOnSelectionMode | null): string {
    if (!mode) return '—';
    return t(`catalogue.selection_modes.${mode}`);
}

function categoryName(id: number | null): string {
    if (id === null) return t('catalogue.uncategorized');
    const match = categories.value.find((c) => c.id === id);
    return match?.name ?? '—';
}

function statusBadgeClass(status: string | null): string {
    return status === 'active'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-200 text-slate-700';
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    return t(`catalogue.statuses.${status}`);
}

// =================== Phase 6c — Delivery providers tab ===================

// ---- Provider modal --------------------------------------------
const providerModalOpen = ref(false);
const providerModalMode = ref<'create' | 'edit'>('create');
const providerModalBusy = ref(false);
const providerModalError = ref<string | null>(null);
const providerModalTarget = ref<DeliveryProvider | null>(null);
const providerForm = reactive<{ name: string; color: string; commission_percent: number; is_active: boolean; sort_order: number }>({
    name: '',
    color: '',
    commission_percent: 0,
    is_active: true,
    sort_order: 0,
});

function openCreateProvider(): void {
    providerModalMode.value = 'create';
    providerModalTarget.value = null;
    providerForm.name = '';
    providerForm.color = '';
    providerForm.commission_percent = 0;
    providerForm.is_active = true;
    providerForm.sort_order = (deliveryProviders.value.length + 1) * 10;
    providerModalError.value = null;
    providerModalOpen.value = true;
}

function openEditProvider(p: DeliveryProvider): void {
    providerModalMode.value = 'edit';
    providerModalTarget.value = p;
    providerForm.name = p.name;
    providerForm.color = p.color ?? '';
    providerForm.commission_percent = Number(p.commission_percent ?? 0);
    providerForm.is_active = p.is_active;
    providerForm.sort_order = p.sort_order;
    providerModalError.value = null;
    providerModalOpen.value = true;
}

async function submitProvider(): Promise<void> {
    providerModalBusy.value = true;
    providerModalError.value = null;
    const payload = {
        name: providerForm.name,
        color: providerForm.color || null,
        // P-G7 — the provider's cut (expected payout = punched − this %).
        commission_percent: providerForm.commission_percent,
        is_active: providerForm.is_active,
        sort_order: providerForm.sort_order,
    };
    try {
        if (providerModalMode.value === 'create') {
            const response = await createDeliveryProvider(payload);
            deliveryProviders.value = [...deliveryProviders.value, response.data]
                .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name));
        } else if (providerModalTarget.value) {
            const response = await updateDeliveryProvider(providerModalTarget.value.uuid, payload);
            const idx = deliveryProviders.value.findIndex((p) => p.uuid === response.data.uuid);
            if (idx >= 0) deliveryProviders.value[idx] = response.data;
        }
        providerModalOpen.value = false;
    } catch (err) {
        if (err instanceof ApiError) {
            const p = err.payload as { message?: string } | null;
            providerModalError.value = p?.message ?? t('delivery_providers.errors.save_failed');
        } else {
            providerModalError.value = t('delivery_providers.errors.save_failed');
        }
    } finally {
        providerModalBusy.value = false;
    }
}

// ---- Provider delete ----------------------------------------
const providerToDelete = ref<DeliveryProvider | null>(null);
const providerDeleteBusy = ref(false);

async function performProviderDelete(): Promise<void> {
    if (!providerToDelete.value) return;
    providerDeleteBusy.value = true;
    try {
        await deleteDeliveryProvider(providerToDelete.value.uuid);
        deliveryProviders.value = deliveryProviders.value.filter(
            (p) => p.uuid !== providerToDelete.value!.uuid,
        );
        providerToDelete.value = null;
    } catch (err) {
        // P-G7 — the server now 422s while the provider has deliveries
        // awaiting verification; surface that instead of failing silently.
        if (err instanceof ApiError) {
            const p = err.payload as { message?: string } | null;
            error.value = p?.message ?? t('delivery_providers.errors.save_failed');
        }
        providerToDelete.value = null;
    } finally {
        providerDeleteBusy.value = false;
    }
}

// =================== Phase 6c — Product provider-price grid ===================

// Map of provider_uuid -> string price input. Populated when
// the product modal opens (from product.delivery_provider_prices
// if loaded, else fetched). Empty string = "no override" (will
// fall back to delivery_price or base_price at POS time).
const productProviderPrices = ref<Record<string, string>>({});
const productProviderPricesLoading = ref(false);
const productProviderPricesError = ref<string | null>(null);
const productProviderPricesTouched = ref<Record<string, boolean>>({});

async function loadProductProviderPrices(productUuid: string | null): Promise<void> {
    productProviderPrices.value = {};
    productProviderPricesTouched.value = {};
    productProviderPricesError.value = null;
    if (productUuid === null) return; // create flow — no prices yet
    productProviderPricesLoading.value = true;
    try {
        const response = await listProductDeliveryPrices(productUuid);
        for (const row of response.data) {
            const providerUuid = row.delivery_provider?.uuid;
            if (providerUuid) {
                productProviderPrices.value[providerUuid] = row.price;
            }
        }
    } catch (err) {
        productProviderPricesError.value = err instanceof Error
            ? err.message
            : t('delivery_providers.errors.prices_load_failed');
    } finally {
        productProviderPricesLoading.value = false;
    }
}

function markProviderPriceTouched(providerUuid: string): void {
    productProviderPricesTouched.value[providerUuid] = true;
}

/**
 * After the product update PATCH succeeds, fire one round-trip
 * per TOUCHED provider price. The Action layer's idempotent
 * skip avoids no-op writes server-side; we still skip them
 * here to reduce network chatter.
 */
async function syncProductProviderPrices(productUuid: string): Promise<void> {
    for (const provider of deliveryProviders.value) {
        if (!productProviderPricesTouched.value[provider.uuid]) continue;
        const value = (productProviderPrices.value[provider.uuid] ?? '').trim();
        try {
            if (value === '') {
                await removeProductDeliveryPrice(productUuid, provider.uuid);
            } else {
                await setProductDeliveryPrice(productUuid, provider.uuid, { price: value });
            }
        } catch {
            // Don't fail the parent flow on a single price
            // sync error -- the modal will still close. The
            // merchant can retry the specific row.
        }
    }
}

// =================== v2 #6 — product-unique add-on groups ===================
// Groups privately owned by THIS product (separate from the
// shared addon_group_uuids picker). Only meaningful when editing
// a saved product — owned groups attach to a persisted product
// id, so create mode shows a "save first" hint instead.

const ownedAddonGroups = ref<AddOnGroup[]>([]);
const ownedAddonsBusy = ref(false);
const ownedAddonsError = ref<string | null>(null);
// Per-group inline "add option" form state, keyed by group uuid.
// price_delta: '' / '0.000' while blank, a NUMBER once typed (number-input v-model).
const ownedOptionForms = ref<Record<string, { name: string; price_delta: string | number }>>({});
// Inline "add a group" form.
const ownedGroupForm = reactive<{ name: string; selection_mode: AddOnSelectionMode }>({
    name: '',
    selection_mode: 'single',
});

function resetOwnedAddons(): void {
    ownedAddonGroups.value = [];
    ownedOptionForms.value = {};
    ownedAddonsError.value = null;
    ownedGroupForm.name = '';
    ownedGroupForm.selection_mode = 'single';
}

function optionFormFor(groupUuid: string): { name: string; price_delta: string | number } {
    return ownedOptionForms.value[groupUuid] ?? { name: '', price_delta: '0.000' };
}

async function loadOwnedAddonGroups(productUuid: string): Promise<void> {
    ownedAddonsBusy.value = true;
    ownedAddonsError.value = null;
    try {
        const response = await getProductAddOnGroups(productUuid);
        ownedAddonGroups.value = response.data;
        // Seed one inline "add option" form per group so the
        // template binds to a stable reactive object (no mutation
        // during render).
        const forms: Record<string, { name: string; price_delta: string | number }> = {};
        for (const group of response.data) {
            forms[group.uuid] = ownedOptionForms.value[group.uuid] ?? { name: '', price_delta: '0.000' };
        }
        ownedOptionForms.value = forms;
    } catch (err) {
        ownedAddonsError.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? t('catalogue.product_addons.save_failed'))
            : t('catalogue.product_addons.save_failed');
    } finally {
        ownedAddonsBusy.value = false;
    }
}

async function refetchOwnedAddonGroups(): Promise<void> {
    if (!prodModalTarget.value) return;
    await loadOwnedAddonGroups(prodModalTarget.value.uuid);
}

async function addOwnedGroup(): Promise<void> {
    if (!prodModalTarget.value || ownedGroupForm.name.trim() === '') return;
    ownedAddonsBusy.value = true;
    ownedAddonsError.value = null;
    try {
        await createProductAddOnGroup(prodModalTarget.value.uuid, {
            name: ownedGroupForm.name.trim(),
            selection_mode: ownedGroupForm.selection_mode,
        });
        ownedGroupForm.name = '';
        ownedGroupForm.selection_mode = 'single';
        await refetchOwnedAddonGroups();
    } catch (err) {
        ownedAddonsError.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? t('catalogue.product_addons.save_failed'))
            : t('catalogue.product_addons.save_failed');
    } finally {
        ownedAddonsBusy.value = false;
    }
}

async function addOwnedOption(groupUuid: string): Promise<void> {
    const form = ownedOptionForms.value[groupUuid];
    if (!form || form.name.trim() === '') return;
    ownedAddonsBusy.value = true;
    ownedAddonsError.value = null;
    try {
        // price_delta rides a type="number" input — a typed value is a NUMBER
        // (Vue v-model auto-cast), so coerce before trimming.
        const priceDelta = String(form.price_delta ?? '').trim();
        await createAddOn(groupUuid, {
            name: form.name.trim(),
            price_delta: priceDelta === '' ? '0.000' : priceDelta,
        });
        form.name = '';
        form.price_delta = '0.000';
        await refetchOwnedAddonGroups();
    } catch (err) {
        ownedAddonsError.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? t('catalogue.product_addons.save_failed'))
            : t('catalogue.product_addons.save_failed');
    } finally {
        ownedAddonsBusy.value = false;
    }
}

async function removeOwnedOption(addonUuid: string): Promise<void> {
    ownedAddonsBusy.value = true;
    ownedAddonsError.value = null;
    try {
        await deleteAddOn(addonUuid);
        await refetchOwnedAddonGroups();
    } catch (err) {
        ownedAddonsError.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? t('catalogue.product_addons.save_failed'))
            : t('catalogue.product_addons.save_failed');
    } finally {
        ownedAddonsBusy.value = false;
    }
}

async function removeOwnedGroup(groupUuid: string): Promise<void> {
    ownedAddonsBusy.value = true;
    ownedAddonsError.value = null;
    try {
        await deleteAddOnGroup(groupUuid);
        await refetchOwnedAddonGroups();
    } catch (err) {
        ownedAddonsError.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? t('catalogue.product_addons.save_failed'))
            : t('catalogue.product_addons.save_failed');
    } finally {
        ownedAddonsBusy.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('catalogue.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('catalogue.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('catalogue.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    type="button"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'categories' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'categories'"
                >
                    <Layers class="size-4" />
                    {{ t('catalogue.tabs.categories') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ categories.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'products' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'products'"
                >
                    <Package class="size-4" />
                    {{ t('catalogue.tabs.products') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ productsMeta.total }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'addons' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'addons'"
                >
                    <Sparkles class="size-4" />
                    {{ t('catalogue.tabs.addons') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ addOnGroups.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'providers' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'providers'"
                >
                    <Truck class="size-4" />
                    {{ t('catalogue.tabs.providers') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ deliveryProviders.length }}</span>
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- =========== CATEGORIES TAB =========== -->
            <section v-if="activeTab === 'categories'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateCategory"
                    >
                        <Plus class="size-4" />
                        {{ t('catalogue.actions.add_category') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="categories.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Layers class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('catalogue.empty_categories') }}</p>
                </div>
                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="cat in categories"
                        :key="cat.id"
                        class="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-200"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ cat.name }}</h2>
                                <p v-if="cat.name_ar" class="text-xs text-slate-500" dir="rtl">{{ cat.name_ar }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(cat.status)">
                                {{ statusLabel(cat.status) }}
                            </span>
                        </header>
                        <p v-if="cat.description" class="text-xs text-slate-600">{{ cat.description }}</p>
                        <p class="text-xs text-slate-500">{{ t('catalogue.product_count', { count: cat.products_count }) }}</p>
                        <div v-if="canManage" class="mt-auto flex gap-1.5 pt-2">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditCategory(cat)">
                                <Pencil class="size-3" /> {{ t('catalogue.actions.edit') }}
                            </button>
                            <button
                                type="button"
                                :disabled="cat.products_count > 0"
                                :title="cat.products_count > 0 ? t('catalogue.delete_category_blocked') : ''"
                                class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="catDeleteTarget = cat"
                            >
                                <Trash2 class="size-3" /> {{ t('catalogue.actions.delete') }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- =========== PRODUCTS TAB =========== -->
            <section v-if="activeTab === 'products'" class="space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="block w-full max-w-xs">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.filter.search') }}</span>
                            <input
                                v-model="productSearch"
                                type="search"
                                :placeholder="t('catalogue.search_placeholder')"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </label>
                        <label class="block w-full max-w-xs">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.filter.category') }}</span>
                            <select
                                v-model="productCategoryFilter"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option value="">{{ t('catalogue.filter.all') }}</option>
                                <option v-for="cat in categories" :key="cat.uuid" :value="cat.uuid">{{ cat.name }}</option>
                            </select>
                        </label>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        :disabled="categories.length === 0"
                        :title="categories.length === 0 ? t('catalogue.no_categories_hint') : ''"
                        @click="openCreateProduct"
                    >
                        <Plus class="size-4" />
                        {{ t('catalogue.actions.add_product') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="products.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Package class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('catalogue.empty_products') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.product') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.category') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.sku') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.price') }}</th>
                                <!-- Phase 5b — recipe cost + has-recipe badge columns. -->
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table_cost_col') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table_recipe_col') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="prod in products" :key="prod.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ prod.name }}</span>
                                    <span v-if="prod.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ prod.name_ar }}</span>
                                    <!-- P-G2 — internal items never reach the POS
                                         menu; flag them so the list reads honestly. -->
                                    <span
                                        v-if="prod.is_internal"
                                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-600"
                                    >
                                        <Package class="size-3" />
                                        Internal
                                    </span>
                                    <!-- G1 — clock badge when a daily availability
                                         window is set. -->
                                    <span
                                        v-if="availabilityWindowLabel(prod)"
                                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-sky-700"
                                        :title="t('catalogue.fields.available_hours')"
                                    >
                                        <Clock3 class="size-3" />
                                        {{ availabilityWindowLabel(prod) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ categoryName(prod.category_id) }}</td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ prod.sku ?? '—' }}</td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums text-slate-950">
                                    {{ prod.base_price }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-500">OMR</span>
                                </td>
                                <!-- Phase 5b — cost column. Dash for no-recipe
                                     products; otherwise the live theoretical
                                     cost at current ingredient prices. -->
                                <td class="px-5 py-4 text-end text-xs tabular-nums text-slate-600">
                                    <template v-if="prod.has_recipe">
                                        {{ prod.theoretical_cost }}
                                        <span class="ms-1 text-[10px] font-normal text-slate-400">OMR</span>
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <!-- Phase 5b — recipe badge. Shows ingredient
                                     count when set; dash otherwise. -->
                                <td class="px-5 py-4">
                                    <span
                                        v-if="prod.has_recipe"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-700"
                                    >
                                        <Beaker class="size-3" />
                                        {{ t('catalogue.recipe.badge', { count: (prod.recipe_lines ?? []).length }) }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(prod.status)">
                                        {{ statusLabel(prod.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex gap-2">
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditProduct(prod)">
                                            <Pencil class="size-3" /> {{ t('catalogue.actions.edit') }}
                                        </button>
                                        <button v-if="prod.stock_mode === 'unit' || prod.stock_mode === 'cooked'" type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-50" @click="openStockDialog(prod)">
                                            <Boxes class="size-3" /> Stock
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="prodDeleteTarget = prod">
                                            <Trash2 class="size-3" /> {{ t('catalogue.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- v2 #12 — server-side pager. Hidden until there's more
                     than one page so single-page catalogues stay clean. -->
                <Pagination
                    v-if="!loading && productsMeta.last_page > 1"
                    :meta="productsMeta"
                    :loading="productsLoading"
                    @update:page="goProductPage"
                />
            </section>

            <!-- =========== ADD-ONS TAB (Phase 4.9) =========== -->
            <section v-if="activeTab === 'addons'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateAddOnGroup"
                    >
                        <Plus class="size-4" />
                        {{ t('catalogue.actions.add_addon_group') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="addOnGroups.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Sparkles class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('catalogue.empty_addon_groups') }}</p>
                </div>
                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <article
                        v-for="group in addOnGroups"
                        :key="group.id"
                        class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-200"
                    >
                        <!-- Group header -->
                        <header class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h2 class="text-base font-semibold text-slate-950 truncate">{{ group.name }}</h2>
                                    <span
                                        v-if="group.is_global"
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-indigo-700"
                                        :title="t('catalogue.fields.is_global_hint')"
                                    >
                                        <Globe2 class="size-3" />
                                        {{ t('catalogue.addon_group_card.global_badge') }}
                                    </span>
                                </div>
                                <p v-if="group.name_ar" class="text-xs text-slate-500" dir="rtl">{{ group.name_ar }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    <Boxes class="me-1 inline size-3" />
                                    {{ selectionModeLabel(group.selection_mode) }}
                                    <span class="mx-1 text-slate-300">•</span>
                                    {{ t('catalogue.addon_count', { count: group.addons_count ?? group.addons?.length ?? 0 }) }}
                                </p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(group.status)">
                                {{ statusLabel(group.status) }}
                            </span>
                        </header>

                        <!-- Options list -->
                        <ul v-if="(group.addons ?? []).length > 0" class="divide-y divide-slate-100 rounded-lg border border-slate-100 bg-slate-50/40">
                            <li
                                v-for="addon in group.addons"
                                :key="addon.id"
                                class="flex items-center justify-between gap-2 px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900">{{ addon.name }}</span>
                                    <span v-if="addon.name_ar" class="block truncate text-xs text-slate-500" dir="rtl">{{ addon.name_ar }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold tabular-nums text-slate-700">
                                        +{{ addon.price_delta }}
                                        <span class="text-[10px] font-normal text-slate-400">OMR</span>
                                    </span>
                                    <span
                                        v-if="addon.status === 'inactive'"
                                        class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-slate-600"
                                    >
                                        {{ t('catalogue.statuses.inactive') }}
                                    </span>
                                    <template v-if="canManage">
                                        <button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-200 hover:text-slate-700" :title="t('catalogue.actions.edit')" @click="openEditAddOn(group, addon)">
                                            <Pencil class="size-3.5" />
                                        </button>
                                        <button type="button" class="rounded p-1 text-rose-500 hover:bg-rose-100 hover:text-rose-700" :title="t('catalogue.actions.delete')" @click="aoDeleteTarget = addon">
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </template>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.actions.add_addon') }}
                        </p>

                        <!-- Group footer actions -->
                        <div v-if="canManage" class="mt-auto flex flex-wrap gap-1.5 pt-1">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100" @click="openCreateAddOn(group)">
                                <Plus class="size-3" /> {{ t('catalogue.actions.add_addon') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditAddOnGroup(group)">
                                <Pencil class="size-3" /> {{ t('catalogue.actions.edit') }}
                            </button>
                            <button
                                type="button"
                                :disabled="(group.products_count ?? 0) > 0"
                                :title="(group.products_count ?? 0) > 0 ? t('catalogue.delete_addon_group_blocked') : ''"
                                class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="agDeleteTarget = group"
                            >
                                <Trash2 class="size-3" /> {{ t('catalogue.actions.delete') }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- =============== Phase 6c — PROVIDERS TAB =============== -->
            <section v-if="activeTab === 'providers'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-600">{{ t('delivery_providers.subtitle') }}</p>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700"
                        @click="openCreateProvider"
                    >
                        <Plus class="size-4" />
                        {{ t('delivery_providers.actions.add') }}
                    </button>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div v-if="deliveryProviders.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                        <Truck class="size-10 text-slate-300" />
                        <p class="text-sm font-semibold">{{ t('delivery_providers.empty_state') }}</p>
                    </div>
                    <table v-else class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('delivery_providers.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('delivery_providers.table.prices_count') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('delivery_providers.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('delivery_providers.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="p in deliveryProviders" :key="p.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center gap-2">
                                        <span v-if="p.color" class="inline-block size-3 rounded-full border border-slate-200" :style="{ backgroundColor: p.color }"></span>
                                        <span class="text-sm font-semibold text-slate-950">{{ p.name }}</span>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ p.prices_count ?? 0 }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="p.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'">
                                        {{ p.is_active ? t('delivery_providers.statuses.active') : t('delivery_providers.statuses.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="openEditProvider(p)">
                                            <Pencil class="size-3.5" />
                                            {{ t('delivery_providers.actions.edit') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50" @click="providerToDelete = p">
                                            <Trash2 class="size-3.5" />
                                            {{ t('delivery_providers.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- =============== Phase 6c — PROVIDER CREATE/EDIT MODAL =============== -->
        <BaseModal
            v-if="providerModalOpen"
            :title="providerModalMode === 'create' ? t('delivery_providers.modal.create_title') : t('delivery_providers.modal.edit_title')"
            size="md"
            :loading="providerModalBusy"
            @close="providerModalOpen = false"
        >
            <form id="provider-modal-form" class="space-y-4" @submit.prevent="submitProvider">
                <div v-if="providerModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ providerModalError }}
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ t('delivery_providers.fields.name') }}</label>
                    <input v-model="providerForm.name" type="text" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ t('delivery_providers.fields.color') }}</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input v-model="providerForm.color" type="text" placeholder="#FF6B00" maxlength="7" class="block w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <input v-model="providerForm.color" type="color" class="size-9 cursor-pointer rounded border border-slate-200">
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ t('delivery_providers.fields.color_hint') }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ t('delivery_providers.fields.commission_percent') }}</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input v-model.number="providerForm.commission_percent" type="number" min="0" max="100" step="0.01" class="block w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <span class="text-sm text-slate-500">%</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ t('delivery_providers.fields.commission_hint') }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ t('delivery_providers.fields.sort_order') }}</label>
                    <input v-model.number="providerForm.sort_order" type="number" min="0" max="65535" class="mt-1 block w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </div>
                <label class="flex items-center gap-2">
                    <input v-model="providerForm.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    <span class="text-sm font-medium text-slate-700">{{ t('delivery_providers.fields.is_active') }}</span>
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="providerModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="provider-modal-form" :disabled="providerModalBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-50">
                        {{ providerModalBusy ? t('common.saving') : t('common.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== Phase 6c — PROVIDER DELETE CONFIRM =============== -->
        <BaseModal
            v-if="providerToDelete"
            :title="t('delivery_providers.delete.title')"
            size="md"
            :loading="providerDeleteBusy"
            @close="providerToDelete = null"
        >
            <p class="text-sm text-slate-600">{{ t('delivery_providers.delete.confirm', { name: providerToDelete.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="providerToDelete = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="providerDeleteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 disabled:opacity-50" @click="performProviderDelete">
                        {{ providerDeleteBusy ? t('common.deleting') : t('delivery_providers.actions.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== CATEGORY MODAL =============== -->
        <BaseModal
            v-if="catModalOpen"
            :title="catModalMode === 'create' ? t('catalogue.cat_modal.create_title') : t('catalogue.cat_modal.edit_title')"
            size="lg"
            :loading="catModalBusy"
            @close="catModalOpen = false"
        >
            <form id="cat-modal-form" class="space-y-4" @submit.prevent="submitCategory">
                <div v-if="catModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ catModalError }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                        <input v-model="catForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="catModalErrors.name" class="mt-1 text-xs text-rose-600">{{ catModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                        <input v-model="catForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.description') }}</span>
                    <textarea v-model="catForm.description" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">
                        <Image class="me-1 inline size-3" />
                        {{ t('catalogue.fields.image_url') }}
                    </span>
                    <input v-model="catForm.image_url" type="url" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <div v-if="catModalMode === 'edit'" class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.display_order') }}</span>
                        <input v-model.number="catForm.display_order" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                        <select v-model="catForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('catalogue.statuses.active') }}</option>
                            <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                        </select>
                    </label>
                </div>

                <!-- Phase D2 - category branch availability (all or selected).
                     Same picker pattern as the product modal, minus the
                     per-branch stock column. -->
                <fieldset class="rounded-lg border border-slate-200 p-3">
                    <legend class="px-2 text-sm font-semibold text-slate-700">
                        <Building2 class="me-1 inline size-3.5 text-teal-600" />
                        {{ t('catalogue.cat_branches.section_title') }}
                    </legend>
                    <p class="mb-2 text-xs text-slate-500">{{ t('catalogue.cat_branches.section_hint') }}</p>

                    <label class="mb-2 flex items-center gap-2 text-xs font-medium text-slate-700">
                        <input
                            v-model="catForm.branch_all"
                            type="checkbox"
                            class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                        >
                        {{ t('catalogue.branches.all_branches') }}
                    </label>

                    <div v-if="catForm.branch_all" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                        {{ t('catalogue.cat_branches.all_branches_hint') }}
                    </div>
                    <div v-else-if="branches.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                        {{ t('catalogue.branches.no_branches') }}
                    </div>
                    <div v-else class="grid gap-1.5 sm:grid-cols-2">
                        <label
                            v-for="b in branches"
                            :key="b.id"
                            class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            <input
                                v-model="catForm.branch_ids"
                                type="checkbox"
                                :value="b.id"
                                class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                            >
                            <span class="truncate">{{ b.name }}</span>
                        </label>
                    </div>
                    <p v-if="catModalErrors.branch_ids" class="mt-1 text-xs text-rose-600">{{ catModalErrors.branch_ids[0] }}</p>
                </fieldset>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="catModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="cat-modal-form" :disabled="catModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ catModalBusy ? t('catalogue.cat_modal.submitting') : t('catalogue.cat_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== PRODUCT MODAL =============== -->
        <BaseModal
            v-if="prodModalOpen"
            :title="prodModalMode === 'create' ? t('catalogue.prod_modal.create_title') : t('catalogue.prod_modal.edit_title')"
            size="2xl"
            :loading="prodModalBusy"
            @close="prodModalOpen = false"
        >
            <form id="prod-modal-form" class="space-y-4" @submit.prevent="submitProduct">
                <div v-if="prodModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ prodModalError }}
                </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                            <input v-model="prodForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="prodModalErrors.name" class="mt-1 text-xs text-rose-600">{{ prodModalErrors.name[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                            <input v-model="prodForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.category') }}</span>
                        <select v-model="prodForm.category_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="null">{{ t('catalogue.uncategorized') }}</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                        <p v-if="prodModalErrors.category_id" class="mt-1 text-xs text-rose-600">{{ prodModalErrors.category_id[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.description') }}</span>
                        <textarea v-model="prodForm.description" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <Image class="me-1 inline size-3" />
                            {{ t('catalogue.fields.image_url') }}
                        </span>
                        <input v-model="prodForm.image_url" type="url" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.sku') }}</span>
                            <input v-model="prodForm.sku" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="prodModalErrors.sku" class="mt-1 text-xs text-rose-600">{{ prodModalErrors.sku[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.barcode') }}</span>
                            <input v-model="prodForm.barcode" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="prodModalErrors.barcode" class="mt-1 text-xs text-rose-600">{{ prodModalErrors.barcode[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">
                                <Tag class="me-1 inline size-3" />
                                {{ t('catalogue.fields.base_price') }} (OMR) *
                            </span>
                            <input v-model="prodForm.base_price" required type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="prodModalErrors.base_price" class="mt-1 text-xs text-rose-600">{{ prodModalErrors.base_price[0] }}</p>
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <!-- Phase 4.9 — delivery_price input. Hint
                             tells the merchant what "blank" means. -->
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">
                                <Truck class="me-1 inline size-3" />
                                {{ t('catalogue.fields.delivery_price') }} (OMR)
                            </span>
                            <input
                                v-model="prodForm.delivery_price"
                                type="number"
                                step="0.001"
                                min="0"
                                :placeholder="prodForm.base_price || '—'"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.delivery_price_hint') }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.cost_price') }} (OMR)</span>
                            <input v-model="prodForm.cost_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.tax_rate') }} (%)</span>
                            <input v-model="prodForm.tax_rate" type="number" step="0.01" min="0" max="100" :placeholder="t('catalogue.fields.tax_rate_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.tax_rate_hint') }}</p>
                        </label>
                    </div>

                    <!-- Phase D2 - §5.5.3 tax-inclusive flag. STORED + LABELLED
                         only for now; totals still add company taxes on top. -->
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                v-model="prodForm.tax_inclusive"
                                type="checkbox"
                                class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                            >
                            {{ t('catalogue.fields.tax_inclusive') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.tax_inclusive_hint') }}</p>
                    </div>

                    <label v-if="prodModalMode === 'edit'" class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                        <select v-model="prodForm.status" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('catalogue.statuses.active') }}</option>
                            <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                        </select>
                    </label>

                    <!-- Phase D2 - §5.5.3 customer tablet visibility. Consumed by
                         the future customer-facing tablet menu; the staff POS
                         product grid is unaffected. -->
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                v-model="prodForm.show_on_customer_tablet"
                                type="checkbox"
                                class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                            >
                            {{ t('catalogue.fields.show_on_tablet') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.show_on_tablet_hint') }}</p>
                    </div>

                    <!-- G1 — menu time-window. Two nullable time inputs, the
                         Discounts time_start/time_end dance: HH:mm in the
                         input, HH:MM:SS on the wire, empty = no bound. Both
                         empty = always available; end before start wraps
                         midnight (overnight menus). -->
                    <div>
                        <p class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.available_hours') }}</p>
                        <div class="mt-1 grid max-w-md grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_from') }}</label>
                                <input v-model="prodForm.available_from" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_until') }}</label>
                                <input v-model="prodForm.available_until" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.available_hours_hint') }}</p>
                    </div>

                    <!-- Phase 7 — stock tracking mode. P-G1 adds Cooked:
                         recipe consumed at PRODUCTION by the kitchen, sells
                         from branch shelf stock like unit. -->
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Stock tracking</span>
                        <select v-model="prodForm.stock_mode" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="untracked">No stock tracking</option>
                            <option value="ingredient">Made to order (recipe consumed at sale)</option>
                            <option value="cooked">Cooked (kitchen produces batches ahead of sale)</option>
                            <option value="unit">Ready / bought-in (count pieces)</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">
                            <template v-if="prodForm.stock_mode === 'unit'">Tracked by piece count. Manage the central pool + branch counts from the <strong>Stock</strong> button on the product row{{ prodModalMode === 'create' ? ' after saving' : '' }}.</template>
                            <template v-else-if="prodForm.stock_mode === 'ingredient'">Availability comes from its recipe + per-branch ingredient stock — no piece count.</template>
                            <template v-else-if="prodForm.stock_mode === 'cooked'">The kitchen produces batches from the recipe (ingredients are consumed at production); the finished pieces sell down from branch shelf stock. Starts SOLD OUT until the kitchen produces.</template>
                            <template v-else>Sold freely — no stock is tracked.</template>
                        </p>
                    </label>

                    <!-- Phase D2 - LOW STOCK badge threshold for piece-counted
                         products (unit + P-G1 cooked). Ingredient-mode badges
                         derive from each ingredient's own minimum instead. -->
                    <label v-if="prodForm.stock_mode === 'unit' || prodForm.stock_mode === 'cooked'" class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.low_stock_threshold') }}</span>
                        <input
                            v-model="prodForm.low_stock_threshold"
                            type="number"
                            min="0"
                            step="1"
                            :placeholder="t('catalogue.fields.low_stock_threshold_placeholder')"
                            class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.low_stock_threshold_hint') }}</p>
                    </label>

                    <!-- P-G1.5 - default shelf life for cooked products. The
                         chef can override per batch on the Finish dialog;
                         empty = keeps indefinitely (no day-end disposition). -->
                    <label v-if="prodForm.stock_mode === 'cooked'" class="block">
                        <span class="text-sm font-medium text-slate-700">Shelf life (days)</span>
                        <input
                            v-model="prodForm.shelf_life_days"
                            type="number"
                            min="1"
                            max="365"
                            step="1"
                            placeholder="e.g. 1 = same day"
                            class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <p class="mt-1 text-xs text-slate-500">Default expiry for each kitchen batch (the chef can adjust per batch at Finish). Leave empty if it keeps — no day-end disposition will be asked.</p>
                    </label>

                    <!-- P-G2 - internal item (cups / lids / tissue / boxes):
                         a unit-stock product the company buys and counts but
                         never sells directly. -->
                    <label v-if="prodForm.stock_mode === 'unit'" class="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                        <input v-model="prodForm.is_internal" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                        <span>
                            <span class="block text-sm font-medium text-slate-700">Internal item</span>
                            <span class="block text-xs text-slate-500">Never shown on the POS menu or the customer tablet — but fully stock-managed (Receive &amp; Distribute, transfers, counts). For cups, lids, boxes… Attach it to sellable products below via their <strong>Physical items</strong> section.</span>
                        </span>
                    </label>

                    <!-- Phase 4.9 — add-on groups picker. Only
                         non-global groups appear. Globals are
                         documented in the hint as "always attached". -->
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Sparkles class="me-1 inline size-3.5 text-teal-600" />
                            {{ t('catalogue.fields.addon_groups') }}
                        </legend>
                        <p class="mb-2 text-xs text-slate-500">{{ t('catalogue.fields.addon_groups_hint') }}</p>
                        <div v-if="selectableAddOnGroups.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.empty_addon_groups') }}
                        </div>
                        <div v-else class="grid gap-1.5 sm:grid-cols-2">
                            <label
                                v-for="group in selectableAddOnGroups"
                                :key="group.id"
                                class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                            >
                                <input
                                    v-model="prodForm.addon_group_uuids"
                                    type="checkbox"
                                    :value="group.uuid"
                                    class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                                >
                                <span class="flex-1 truncate">{{ group.name }}</span>
                                <span class="text-[10px] text-slate-400">{{ selectionModeLabel(group.selection_mode) }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- v2 #6 — product-unique add-on groups. Owned
                         privately by THIS product (separate from the
                         shared picker above). Only available once the
                         product is saved (groups attach to a product
                         id). Mirrors the Add-ons tab's group/option
                         card styling for visual consistency. -->
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Sparkles class="me-1 inline size-3.5 text-indigo-600" />
                            {{ t('catalogue.product_addons.title') }}
                        </legend>
                        <p class="mb-2 text-xs text-slate-500">{{ t('catalogue.product_addons.hint') }}</p>

                        <!-- Create mode: owned groups need a saved product id. -->
                        <div v-if="prodModalMode === 'create'" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.product_addons.save_first') }}
                        </div>

                        <template v-else>
                            <div v-if="ownedAddonsError" class="mb-2 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                                {{ ownedAddonsError }}
                            </div>

                            <div v-if="ownedAddonGroups.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.product_addons.empty') }}
                            </div>

                            <div v-else class="space-y-3">
                                <article
                                    v-for="group in ownedAddonGroups"
                                    :key="group.id"
                                    class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                                >
                                    <header class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="truncate text-sm font-semibold text-slate-950">{{ group.name }}</h3>
                                            <p class="mt-0.5 text-[11px] text-slate-500">
                                                <Boxes class="me-1 inline size-3" />
                                                {{ selectionModeLabel(group.selection_mode) }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            :disabled="ownedAddonsBusy"
                                            class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="removeOwnedGroup(group.uuid)"
                                        >
                                            <Trash2 class="size-3" /> {{ t('catalogue.product_addons.delete_group') }}
                                        </button>
                                    </header>

                                    <!-- Options list -->
                                    <ul v-if="(group.addons ?? []).length > 0" class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-100 bg-slate-50/40">
                                        <li
                                            v-for="option in group.addons"
                                            :key="option.id"
                                            class="flex items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ option.name }}</span>
                                            <span class="text-xs font-semibold tabular-nums text-slate-700">
                                                +{{ option.price_delta }}
                                                <span class="text-[10px] font-normal text-slate-400">OMR</span>
                                            </span>
                                            <button
                                                type="button"
                                                :disabled="ownedAddonsBusy"
                                                class="rounded p-1 text-rose-500 transition hover:bg-rose-100 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                :title="t('catalogue.product_addons.remove')"
                                                @click="removeOwnedOption(option.uuid)"
                                            >
                                                <Trash2 class="size-3.5" />
                                            </button>
                                        </li>
                                    </ul>

                                    <!-- Add option inline form -->
                                    <div class="mt-2 flex flex-wrap items-end gap-2">
                                        <label class="block flex-1 min-w-[8rem]">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.option_name') }}</span>
                                            <input
                                                v-model="optionFormFor(group.uuid).name"
                                                type="text"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                            >
                                        </label>
                                        <label class="block w-28">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.price_delta') }}</span>
                                            <input
                                                v-model="optionFormFor(group.uuid).price_delta"
                                                type="number"
                                                step="0.001"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                            >
                                        </label>
                                        <button
                                            type="button"
                                            :disabled="ownedAddonsBusy || optionFormFor(group.uuid).name.trim() === ''"
                                            class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2.5 py-1.5 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="addOwnedOption(group.uuid)"
                                        >
                                            <Plus class="size-3" /> {{ t('catalogue.product_addons.add_option') }}
                                        </button>
                                    </div>
                                </article>
                            </div>

                            <!-- Add a group inline form -->
                            <div class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-slate-200 p-2.5">
                                <label class="block flex-1 min-w-[8rem]">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.group_name') }}</span>
                                    <input
                                        v-model="ownedGroupForm.name"
                                        type="text"
                                        class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    >
                                </label>
                                <label class="block w-36">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.selection_modes.single') }} / {{ t('catalogue.selection_modes.multi') }}</span>
                                    <select
                                        v-model="ownedGroupForm.selection_mode"
                                        class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    >
                                        <option value="single">{{ t('catalogue.selection_modes.single') }}</option>
                                        <option value="multi">{{ t('catalogue.selection_modes.multi') }}</option>
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    :disabled="ownedAddonsBusy || ownedGroupForm.name.trim() === ''"
                                    class="inline-flex items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="addOwnedGroup"
                                >
                                    <Plus class="size-3.5" /> {{ t('catalogue.product_addons.add_group') }}
                                </button>
                            </div>
                        </template>
                    </fieldset>

                    <!-- Phase B - per-branch availability + unit stock.
                         "All branches" = no rows (available everywhere).
                         Otherwise tick the branches that sell this product
                         and optionally set per-branch units. Branch
                         location + devices are admin-managed. -->
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Building2 class="me-1 inline size-3.5 text-teal-600" />
                            {{ t('catalogue.branches.section_title') }}
                        </legend>
                        <p class="mb-2 text-xs text-slate-500">{{ t('catalogue.branches.section_hint') }}</p>

                        <label class="mb-2 flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input
                                v-model="prodForm.branch_all"
                                type="checkbox"
                                class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                            >
                            {{ t('catalogue.branches.all_branches') }}
                        </label>

                        <div v-if="prodForm.branch_all" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.branches.all_branches_hint') }}
                        </div>
                        <div v-else-if="prodForm.branch_rows.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.branches.no_branches') }}
                        </div>
                        <ul v-else class="space-y-1.5">
                            <li
                                v-for="(row, idx) in prodForm.branch_rows"
                                :key="row.branch_id"
                                class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs"
                            >
                                <label class="flex flex-1 items-center gap-2 font-medium text-slate-700">
                                    <input
                                        v-model="prodForm.branch_rows[idx].selected"
                                        type="checkbox"
                                        class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                                    >
                                    <span class="truncate">{{ branchName(row.branch_id) }}</span>
                                </label>
                                <input
                                    v-model="prodForm.branch_rows[idx].stock_qty"
                                    type="number"
                                    min="0"
                                    step="1"
                                    :disabled="!row.selected"
                                    :placeholder="t('catalogue.branches.stock_placeholder')"
                                    class="w-24 rounded border border-slate-200 px-2 py-1 text-xs tabular-nums disabled:cursor-not-allowed disabled:bg-slate-50"
                                >
                            </li>
                        </ul>
                    </fieldset>

                    <!-- Phase 5b — Recipe section. Each line is an
                         (ingredient, quantity) pair. Empty array
                         on save = "no recipe / pre-made goods, no
                         inventory deduction on sale". Live cost
                         + margin update as the merchant edits. -->
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Beaker class="me-1 inline size-3.5 text-amber-600" />
                            {{ t('catalogue.recipe.section_title') }}
                        </legend>
                        <p class="mb-3 text-xs text-slate-500">{{ t('catalogue.recipe.section_hint') }}</p>

                        <!-- Ingredients available hint when none exist -->
                        <div v-if="ingredients.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('catalogue.recipe.no_ingredients_hint') }}
                        </div>

                        <!-- Recipe lines + add button -->
                        <template v-else>
                            <div v-if="prodForm.recipe_lines.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.recipe.no_lines') }}
                            </div>
                            <ul v-else class="space-y-2">
                                <li
                                    v-for="(line, idx) in prodForm.recipe_lines"
                                    :key="idx"
                                    class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2"
                                >
                                    <label class="flex-1 min-w-xs block">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.fields.ingredient') }}</span>
                                        <select
                                            v-model="line.ingredient_uuid"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                            @change="line.unit = ''"
                                        >
                                            <option value="">{{ t('catalogue.recipe.pick_ingredient') }}</option>
                                            <option v-for="ing in ingredients" :key="ing.id" :value="ing.uuid">
                                                {{ ing.name }} ({{ ing.unit }})
                                            </option>
                                        </select>
                                    </label>
                                    <label class="block w-28">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.quantity') }}</span>
                                        <input
                                            v-model="line.quantity"
                                            type="number"
                                            step="0.001"
                                            min="0.001"
                                            placeholder="0.000"
                                            class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                    </label>
                                    <label class="block w-24">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.unit') }}</span>
                                        <select
                                            v-model="line.unit"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                            <option value="">{{ ingredientUnitLabel(line.ingredient_uuid) }}</option>
                                            <option v-for="au in (ingredientByUuid(line.ingredient_uuid)?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50"
                                        :title="t('catalogue.recipe.remove_line')"
                                        @click="removeRecipeLine(idx)"
                                    >
                                        <Minus class="size-4" />
                                    </button>
                                </li>
                            </ul>
                            <button
                                type="button"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                                @click="addRecipeLine"
                            >
                                <Plus class="size-3.5" />
                                {{ t('catalogue.recipe.add_line') }}
                            </button>

                            <!-- Duplicate-ingredient warning -->
                            <p v-if="recipeHasDuplicates" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                {{ t('catalogue.recipe.duplicate_ingredient') }}
                            </p>

                            <!-- Live cost + margin preview -->
                            <div v-if="prodForm.recipe_lines.length > 0" class="mt-3 grid gap-2 sm:grid-cols-2">
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ t('catalogue.recipe.live_cost') }}</p>
                                    <p class="text-base font-semibold tabular-nums text-amber-900">
                                        {{ recipeLiveCost }} <span class="text-[10px] font-normal text-amber-600">OMR</span>
                                    </p>
                                </div>
                                <div v-if="recipeLiveMargin !== null" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ t('catalogue.recipe.margin') }}</p>
                                    <p class="text-base font-semibold tabular-nums text-emerald-900">
                                        {{ recipeLiveMargin }}<span class="text-[10px] font-normal text-emerald-600">%</span>
                                    </p>
                                </div>
                            </div>
                        </template>
                    </fieldset>

                    <!-- P-G2 — Physical items section: unit-tracked products
                         (cups, lids, boxes...) consumed per unit sold, from
                         the same branch unit stock the Stock dialog manages.
                         Hidden for internal items (a cup has no components). -->
                    <fieldset v-if="!prodForm.is_internal" class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Boxes class="me-1 inline size-3.5 text-sky-600" />
                            Physical items
                        </legend>
                        <p class="mb-3 text-xs text-slate-500">Countable goods consumed when one unit sells — coffee = 1 × cup 12oz + 1 × lid. Deducted from the branch's unit stock at sale and visible in the product's Stock history.</p>

                        <div v-if="componentOptions.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            No unit-tracked products yet. Create one (e.g. "Cup 12oz", stock tracking = Ready / bought-in, Internal item ticked) and it appears here.
                        </div>

                        <template v-else>
                            <div v-if="prodForm.component_rows.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                No physical items attached.
                            </div>
                            <ul v-else class="space-y-2">
                                <li
                                    v-for="(row, idx) in prodForm.component_rows"
                                    :key="idx"
                                    class="flex items-center gap-2"
                                >
                                    <select
                                        v-model="row.component_uuid"
                                        class="w-full flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    >
                                        <option value="" disabled>Pick a unit product…</option>
                                        <option
                                            v-for="opt in componentOptions"
                                            :key="opt.uuid"
                                            :value="opt.uuid"
                                            :disabled="opt.uuid === prodModalTarget?.uuid"
                                        >
                                            {{ opt.name }}{{ opt.is_internal ? ' (internal)' : '' }}
                                        </option>
                                    </select>
                                    <input
                                        v-model="row.quantity"
                                        type="number"
                                        min="0.001"
                                        step="1"
                                        placeholder="Qty / unit"
                                        class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    >
                                    <button
                                        type="button"
                                        class="grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 text-rose-600 transition hover:bg-rose-50"
                                        @click="prodForm.component_rows.splice(idx, 1)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </li>
                            </ul>
                            <button
                                type="button"
                                class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100"
                                @click="prodForm.component_rows.push({ component_uuid: '', quantity: '1' })"
                            >
                                <Plus class="size-3.5" />
                                Add physical item
                            </button>
                        </template>
                    </fieldset>

                    <!-- Phase 6c — Provider pricing section -->
                    <fieldset v-if="deliveryProviders.length > 0" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 space-y-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700 inline-flex items-center gap-2">
                            <Truck class="size-4 text-teal-700" />
                            {{ t('delivery_providers.product_grid.title') }}
                        </legend>
                        <p class="text-xs text-slate-500">
                            {{ t('delivery_providers.product_grid.hint') }}
                        </p>
                        <div v-if="productProviderPricesError" class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                            {{ productProviderPricesError }}
                        </div>
                        <div v-if="productProviderPricesLoading" class="text-xs text-slate-500">{{ t('common.loading') }}</div>
                        <div v-else class="grid gap-2 sm:grid-cols-2">
                            <div v-for="provider in deliveryProviders.filter((p) => p.is_active)" :key="provider.id" class="rounded-lg border border-slate-200 bg-white px-3 py-2 flex items-center gap-3">
                                <span class="inline-flex items-center gap-2 min-w-[7rem] text-sm font-medium text-slate-700">
                                    <span v-if="provider.color" class="inline-block size-3 rounded-full border border-slate-200" :style="{ backgroundColor: provider.color }"></span>
                                    {{ provider.name }}
                                </span>
                                <input
                                    :value="productProviderPrices[provider.uuid] ?? ''"
                                    type="text"
                                    inputmode="decimal"
                                    :placeholder="prodForm.delivery_price || prodForm.base_price || '0.000'"
                                    class="flex-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    :disabled="!canManage"
                                    @input="(e) => { productProviderPrices[provider.uuid] = (e.target as HTMLInputElement).value; markProviderPriceTouched(provider.uuid); }"
                                >
                            </div>
                        </div>
                    </fieldset>

            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="prodModalOpen = false">{{ t('common.cancel') }}</button>
                    <button
                        type="submit"
                        form="prod-modal-form"
                        :disabled="prodModalBusy || recipeHasDuplicates"
                        class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ prodModalBusy ? t('catalogue.prod_modal.submitting') : t('catalogue.prod_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== DELETE CONFIRMS =============== -->
        <BaseModal
            v-if="catDeleteTarget"
            :title="t('catalogue.delete_cat_dialog.title')"
            size="md"
            :loading="deleting"
            @close="catDeleteTarget = null"
        >
            <p class="text-sm text-slate-700">{{ t('catalogue.delete_cat_dialog.body', { name: catDeleteTarget.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="catDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteCategory">
                        {{ deleting ? t('catalogue.delete_cat_dialog.submitting') : t('catalogue.delete_cat_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <BaseModal
            v-if="prodDeleteTarget"
            :title="t('catalogue.delete_prod_dialog.title')"
            size="md"
            :loading="deleting"
            @close="prodDeleteTarget = null"
        >
            <p class="text-sm text-slate-700">{{ t('catalogue.delete_prod_dialog.body', { name: prodDeleteTarget.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="prodDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteProduct">
                        {{ deleting ? t('catalogue.delete_prod_dialog.submitting') : t('catalogue.delete_prod_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== ADD-ON GROUP MODAL (Phase 4.9) =============== -->
        <BaseModal
            v-if="agModalOpen"
            :title="agModalMode === 'create' ? t('catalogue.addon_group_modal.create_title') : t('catalogue.addon_group_modal.edit_title')"
            size="lg"
            :loading="agModalBusy"
            @close="agModalOpen = false"
        >
            <form id="ag-modal-form" class="space-y-4" @submit.prevent="submitAddOnGroup">
                <div v-if="agModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ agModalError }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                        <input v-model="agForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="agModalErrors.name" class="mt-1 text-xs text-rose-600">{{ agModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                        <input v-model="agForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.selection_mode') }}</span>
                    <select v-model="agForm.selection_mode" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option value="single">{{ t('catalogue.selection_modes.single') }}</option>
                        <option value="multi">{{ t('catalogue.selection_modes.multi') }}</option>
                    </select>
                </label>
                <!-- Phase B — selection constraints. min >= 1 makes the group
                     REQUIRED at the POS; blank = unbounded. -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.min_selections') }}</span>
                        <input v-model="agForm.min_selections" type="number" min="0" max="99" :placeholder="t('catalogue.fields.selections_unbounded')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.min_selections_hint') }}</p>
                        <p v-if="agModalErrors.min_selections" class="mt-1 text-xs text-rose-600">{{ agModalErrors.min_selections[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.max_selections') }}</span>
                        <input v-model="agForm.max_selections" type="number" min="1" max="99" :placeholder="t('catalogue.fields.selections_unbounded')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="agModalErrors.max_selections" class="mt-1 text-xs text-rose-600">{{ agModalErrors.max_selections[0] }}</p>
                    </label>
                </div>
                <!-- Phase B — category-level bindings: the group applies to
                     every product in the ticked categories. -->
                <fieldset class="rounded-lg border border-slate-200 p-3">
                    <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('catalogue.fields.bound_categories') }}</legend>
                    <p class="mb-2 text-xs text-slate-500">{{ t('catalogue.fields.bound_categories_hint') }}</p>
                    <p v-if="categories.length === 0" class="text-xs italic text-slate-400">{{ t('catalogue.empty_categories') }}</p>
                    <div v-else class="flex max-h-32 flex-wrap gap-2 overflow-y-auto">
                        <label v-for="cat in categories" :key="cat.id" class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium" :class="agForm.category_ids.includes(cat.id) ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600'">
                            <input v-model="agForm.category_ids" type="checkbox" :value="cat.id" class="size-3 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            {{ cat.name }}
                        </label>
                    </div>
                    <p v-if="agModalErrors.category_ids" class="mt-1 text-xs text-rose-600">{{ agModalErrors.category_ids[0] }}</p>
                </fieldset>
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                    <input v-model="agForm.is_global" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">{{ t('catalogue.fields.is_global') }}</span>
                        <span class="block text-xs text-slate-500">{{ t('catalogue.fields.is_global_hint') }}</span>
                    </span>
                </label>
                <div v-if="agModalMode === 'edit'" class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.display_order') }}</span>
                        <input v-model.number="agForm.display_order" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                        <select v-model="agForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('catalogue.statuses.active') }}</option>
                            <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                        </select>
                    </label>
                </div>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="agModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="ag-modal-form" :disabled="agModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ agModalBusy ? t('catalogue.addon_group_modal.submitting') : t('catalogue.addon_group_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== ADD-ON OPTION MODAL (Phase 4.9) =============== -->
        <BaseModal
            v-if="aoModalOpen"
            :title="aoModalMode === 'create'
                ? t('catalogue.addon_modal.create_title', { group: aoModalParentGroup?.name ?? '' })
                : t('catalogue.addon_modal.edit_title')"
            size="md"
            :loading="aoModalBusy"
            @close="aoModalOpen = false"
        >
            <form id="ao-modal-form" class="space-y-4" @submit.prevent="submitAddOn">
                <div v-if="aoModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ aoModalError }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                        <input v-model="aoForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="aoModalErrors.name" class="mt-1 text-xs text-rose-600">{{ aoModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                        <input v-model="aoForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                </div>
                <!-- P-G3 — the add-on can BE a real product (cake inside a
                     coffee): pick it here and the option consumes the
                     product's real stock at sale; the price below stays the
                     add-on price for THIS group (same or different from the
                     standalone price). -->
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Linked product (optional)</span>
                    <select
                        v-model="aoForm.linked_product_uuid"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        @change="onLinkedProductPicked"
                    >
                        <option value="">None — label-only option</option>
                        <option v-for="opt in addonLinkOptions" :key="opt.uuid" :value="opt.uuid">
                            {{ opt.name }}
                        </option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">When set, selling this add-on consumes the product's real stock (cooked/ready: shelf −1 each, made-to-order: its recipe). The add-on greys out on the POS when the product is sold out.</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.price_delta') }} (OMR)</span>
                    <input v-model="aoForm.price_delta" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="aoModalErrors.price_delta" class="mt-1 text-xs text-rose-600">{{ aoModalErrors.price_delta[0] }}</p>
                </label>
                <!-- Phase B — pre-selected default in the POS customize sheet
                     (in a single-select group only one option can be default). -->
                <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                    <input v-model="aoForm.is_default" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                    <span>
                        <span class="block text-sm font-medium text-slate-700">{{ t('catalogue.fields.is_default') }}</span>
                        <span class="block text-xs text-slate-500">{{ t('catalogue.fields.is_default_hint') }}</span>
                    </span>
                </label>
                <div v-if="aoModalMode === 'edit'" class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.display_order') }}</span>
                        <input v-model.number="aoForm.display_order" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                        <select v-model="aoForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('catalogue.statuses.active') }}</option>
                            <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                        </select>
                    </label>
                </div>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="aoModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="ao-modal-form" :disabled="aoModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ aoModalBusy ? t('catalogue.addon_modal.submitting') : t('catalogue.addon_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- =============== ADD-ON DELETE CONFIRMS (Phase 4.9) =============== -->
        <BaseModal
            v-if="agDeleteTarget"
            :title="t('catalogue.delete_addon_group_dialog.title')"
            size="md"
            :loading="deleting"
            @close="agDeleteTarget = null"
        >
            <p class="text-sm text-slate-700">{{ t('catalogue.delete_addon_group_dialog.body', { name: agDeleteTarget.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="agDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteAddOnGroup">
                        {{ deleting ? t('catalogue.delete_addon_group_dialog.submitting') : t('catalogue.delete_addon_group_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <BaseModal
            v-if="aoDeleteTarget"
            :title="t('catalogue.delete_addon_dialog.title')"
            size="md"
            :loading="deleting"
            @close="aoDeleteTarget = null"
        >
            <p class="text-sm text-slate-700">{{ t('catalogue.delete_addon_dialog.body', { name: aoDeleteTarget.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="aoDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteAddOn">
                        {{ deleting ? t('catalogue.delete_addon_dialog.submitting') : t('catalogue.delete_addon_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <ProductStockDialog
            :open="stockDialogProduct !== null"
            :product-uuid="stockDialogProduct?.uuid ?? null"
            :product-name="stockDialogProduct?.name ?? ''"
            :can-manage="canManage"
            @close="stockDialogProduct = null"
        />
    </MerchantLayout>
</template>

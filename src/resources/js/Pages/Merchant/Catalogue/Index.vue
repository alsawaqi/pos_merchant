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

import { Beaker, Building2, Boxes, Clock3, Globe2, Image, Layers, Package, Pencil, Plus, Sparkles, Trash2, Truck } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
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
    deleteAddOn,
    deleteAddOnGroup,
    deleteCategory,
    deleteProduct,
    listAddOnGroups,
    listAddonLinkOptions,
    listCategories,
    listProducts,
    updateAddOn,
    updateAddOnGroup,
    updateCategory,
    type AddOn,
    type AddOnGroup,
    type AddOnSelectionMode,
    type AddOnStatus,
    type AddonLinkOption,
    type Category,
    type CategoryStatus,
    type Product,
} from '@/lib/api/catalogue';
import { listBranches, type Branch as BranchLite } from '@/lib/api/branches';
import {
    createDeliveryProvider,
    deleteDeliveryProvider,
    listDeliveryProviders,
    updateDeliveryProvider,
    type DeliveryProvider,
} from '@/lib/api/deliveryProviders';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const route = useRoute();
const router = useRouter();

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
        fetchAddonLinkOptions(),
        fetchDeliveryProviders(),
        fetchBranches(),
    ]);
    loading.value = false;
}

onMounted(() => {
    // PD1 — the wizard returns to /catalogue?tab=products; honour the
    // deep link instead of always landing on Categories.
    const requestedTab = String(route.query.tab ?? '');
    if ((['categories', 'products', 'addons', 'providers'] as const).some((k) => k === requestedTab)) {
        activeTab.value = requestedTab as TabKey;
    }
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
                        @click="router.push('/catalogue/products/new')"
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
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="router.push(`/catalogue/products/${prod.uuid}/edit`)">
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

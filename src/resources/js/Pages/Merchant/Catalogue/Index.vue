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

import { Image, Layers, Package, Pencil, Plus, Tag, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createCategory,
    createProduct,
    deleteCategory,
    deleteProduct,
    listCategories,
    listProducts,
    updateCategory,
    updateProduct,
    type Category,
    type CategoryStatus,
    type CreateProductPayload,
    type Product,
    type ProductStatus,
} from '@/lib/api/catalogue';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.CatalogueManage));

type TabKey = 'categories' | 'products';
const activeTab = ref<TabKey>('categories');

// ---- Data --------------------------------------------------------
const categories = ref<Category[]>([]);
const products = ref<Product[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// ---- Filters -----------------------------------------------------
const productCategoryFilter = ref<string>('');

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
}>({
    name: '',
    name_ar: '',
    description: '',
    image_url: '',
    display_order: 0,
    status: 'active',
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
    cost_price: string;
    tax_rate: string;
    display_order: number;
    status: ProductStatus;
}>({
    name: '',
    name_ar: '',
    description: '',
    image_url: '',
    category_id: null,
    sku: '',
    barcode: '',
    base_price: '',
    cost_price: '',
    tax_rate: '',
    display_order: 0,
    status: 'active',
});

// ---- Delete confirms --------------------------------------------
const catDeleteTarget = ref<Category | null>(null);
const prodDeleteTarget = ref<Product | null>(null);
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
    try {
        const response = await listProducts(
            productCategoryFilter.value === '' ? undefined : { category: productCategoryFilter.value },
        );
        products.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load products';
    }
}

async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    await Promise.all([fetchCategories(), fetchProducts()]);
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
    prodForm.cost_price = '';
    prodForm.tax_rate = '';
    prodForm.display_order = products.value.length;
    prodForm.status = 'active';
    prodModalErrors.value = {};
    prodModalError.value = null;
    prodModalOpen.value = true;
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
    prodForm.cost_price = product.cost_price ?? '';
    prodForm.tax_rate = product.tax_rate ?? '';
    prodForm.display_order = product.display_order;
    prodForm.status = (product.status ?? 'active') as ProductStatus;
    prodModalErrors.value = {};
    prodModalError.value = null;
    prodModalOpen.value = true;
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
            cost_price: prodForm.cost_price === '' ? null : prodForm.cost_price,
            tax_rate: prodForm.tax_rate === '' ? null : prodForm.tax_rate,
            display_order: prodForm.display_order,
        };
        if (prodModalMode.value === 'create') {
            await createProduct(payload);
        } else if (prodModalTarget.value) {
            await updateProduct(prodModalTarget.value.uuid, { ...payload, status: prodForm.status });
        }
        prodModalOpen.value = false;
        await fetchProducts();
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
            <div class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
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
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ products.length }}</span>
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
                    <label class="block max-w-xs">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.filter.category') }}</span>
                        <select
                            v-model="productCategoryFilter"
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            @change="fetchProducts"
                        >
                            <option value="">{{ t('catalogue.filter.all') }}</option>
                            <option v-for="cat in categories" :key="cat.uuid" :value="cat.uuid">{{ cat.name }}</option>
                        </select>
                    </label>
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
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="prod in products" :key="prod.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ prod.name }}</span>
                                    <span v-if="prod.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ prod.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ categoryName(prod.category_id) }}</td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ prod.sku ?? '—' }}</td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums text-slate-950">
                                    {{ prod.base_price }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-500">OMR</span>
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
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="prodDeleteTarget = prod">
                                            <Trash2 class="size-3" /> {{ t('catalogue.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- =============== CATEGORY MODAL =============== -->
        <div v-if="catModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ catModalMode === 'create' ? t('catalogue.cat_modal.create_title') : t('catalogue.cat_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitCategory">
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
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="catModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="catModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ catModalBusy ? t('catalogue.cat_modal.submitting') : t('catalogue.cat_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =============== PRODUCT MODAL =============== -->
        <div v-if="prodModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ prodModalMode === 'create' ? t('catalogue.prod_modal.create_title') : t('catalogue.prod_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitProduct">
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
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.cost_price') }} (OMR)</span>
                            <input v-model="prodForm.cost_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.tax_rate') }} (%)</span>
                            <input v-model="prodForm.tax_rate" type="number" step="0.01" min="0" max="100" :placeholder="t('catalogue.fields.tax_rate_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.tax_rate_hint') }}</p>
                        </label>
                        <label v-if="prodModalMode === 'edit'" class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                            <select v-model="prodForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="active">{{ t('catalogue.statuses.active') }}</option>
                                <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="prodModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="prodModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ prodModalBusy ? t('catalogue.prod_modal.submitting') : t('catalogue.prod_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =============== DELETE CONFIRMS =============== -->
        <div v-if="catDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('catalogue.delete_cat_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('catalogue.delete_cat_dialog.body', { name: catDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="catDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteCategory">
                        {{ deleting ? t('catalogue.delete_cat_dialog.submitting') : t('catalogue.delete_cat_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="prodDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('catalogue.delete_prod_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('catalogue.delete_prod_dialog.body', { name: prodDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="prodDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteProduct">
                        {{ deleting ? t('catalogue.delete_prod_dialog.submitting') : t('catalogue.delete_prod_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

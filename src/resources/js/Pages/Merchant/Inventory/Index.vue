<script setup lang="ts">
/**
 * Inventory — Phase 5a.
 *
 * Tabbed page: Ingredients | Suppliers | Branch Stock | Movements.
 *
 *   - Ingredients tab: company-wide master list. CRUD with
 *     unit selector + cost/threshold/supplier inputs.
 *   - Suppliers tab: lightweight per-merchant directory.
 *   - Branch Stock tab: branch picker at top + sortable list
 *     of (ingredient, quantity, health, last_movement). Per-row
 *     Adjust + Restock buttons open dedicated modals.
 *   - Movements tab: paginated append-only ledger with filters
 *     for ingredient + type.
 *
 * Permission gating:
 *   - Page reachable when InventoryView is granted.
 *   - Create / edit / delete buttons + Adjust / Restock only
 *     when InventoryManage is granted. Server is the real gate.
 */

import {
    Boxes,
    Building2,
    History,
    Image as ImageIcon,
    Minus,
    Package,
    Pencil,
    Plus,
    Trash2,
    Truck,
    Users,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    adjustStock,
    createIngredient,
    createSupplier,
    deleteIngredient,
    deleteSupplier,
    listBranchStock,
    listIngredients,
    listStockMovements,
    listSuppliers,
    restockStock,
    updateIngredient,
    updateSupplier,
    type BranchStockRow,
    type Ingredient,
    type IngredientUnit,
    type InventoryStatus,
    type PaginatedMovements,
    type StockMovementType,
    type Supplier,
} from '@/lib/api/inventory';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.InventoryManage));

type TabKey = 'ingredients' | 'suppliers' | 'stock' | 'movements';
const activeTab = ref<TabKey>('ingredients');

// =================== Shared data =================================

const branches = ref<Branch[]>([]);
const selectedBranchUuid = ref<string | null>(null);

const ingredients = ref<Ingredient[]>([]);
const suppliers = ref<Supplier[]>([]);
const branchStock = ref<BranchStockRow[]>([]);
const movements = ref<PaginatedMovements | null>(null);

const loading = ref(true);
const error = ref<string | null>(null);

// =================== Ingredient modal =============================

const ingModalOpen = ref(false);
const ingModalBusy = ref(false);
const ingModalMode = ref<'create' | 'edit'>('create');
const ingModalTarget = ref<Ingredient | null>(null);
const ingModalErrors = ref<Record<string, string[]>>({});
const ingModalError = ref<string | null>(null);
const ingForm = reactive<{
    name: string;
    name_ar: string;
    unit: IngredientUnit;
    default_unit_cost: string;
    min_stock_threshold: string;
    primary_supplier_id: number | null;
    status: InventoryStatus;
}>({
    name: '',
    name_ar: '',
    unit: 'kg',
    default_unit_cost: '0.000',
    min_stock_threshold: '',
    primary_supplier_id: null,
    status: 'active',
});

const unitOptions: IngredientUnit[] = ['kg', 'g', 'l', 'ml', 'piece', 'pack', 'box'];

// =================== Supplier modal ==============================

const supModalOpen = ref(false);
const supModalBusy = ref(false);
const supModalMode = ref<'create' | 'edit'>('create');
const supModalTarget = ref<Supplier | null>(null);
const supModalErrors = ref<Record<string, string[]>>({});
const supModalError = ref<string | null>(null);
const supForm = reactive<{
    name: string;
    contact: string;
    notes: string;
    status: InventoryStatus;
}>({ name: '', contact: '', notes: '', status: 'active' });

// =================== Adjust + Restock modals =====================

const adjustOpen = ref(false);
const adjustBusy = ref(false);
const adjustError = ref<string | null>(null);
const adjustErrors = ref<Record<string, string[]>>({});
const adjustTarget = ref<{ ingredient: Ingredient | null; row: BranchStockRow | null }>({
    ingredient: null,
    row: null,
});
const adjustForm = reactive<{ signed_quantity: string; note: string }>({
    signed_quantity: '',
    note: '',
});

const restockOpen = ref(false);
const restockBusy = ref(false);
const restockError = ref<string | null>(null);
const restockErrors = ref<Record<string, string[]>>({});
const restockTarget = ref<{ ingredient: Ingredient | null; row: BranchStockRow | null }>({
    ingredient: null,
    row: null,
});
const restockForm = reactive<{
    quantity: string;
    unit_cost: string;
    supplier_uuid: string;
    note: string;
}>({ quantity: '', unit_cost: '', supplier_uuid: '', note: '' });

// =================== Delete confirms =============================

const ingDeleteTarget = ref<Ingredient | null>(null);
const supDeleteTarget = ref<Supplier | null>(null);
const deleting = ref(false);

// =================== Movement filters ============================

const movementFilters = reactive<{
    ingredient_uuid: string;
    type: StockMovementType | '';
}>({ ingredient_uuid: '', type: '' });
const movementsPage = ref(1);

// =================== Fetchers ====================================

async function fetchBranches(): Promise<void> {
    try {
        const response = await listBranches();
        branches.value = response.data;
        if (selectedBranchUuid.value === null && branches.value.length > 0) {
            selectedBranchUuid.value = branches.value[0].uuid;
        }
    } catch {
        branches.value = [];
    }
}

async function fetchIngredients(): Promise<void> {
    try {
        const response = await listIngredients();
        ingredients.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load ingredients';
    }
}

async function fetchSuppliers(): Promise<void> {
    try {
        const response = await listSuppliers();
        suppliers.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load suppliers';
    }
}

async function fetchBranchStock(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        branchStock.value = [];
        return;
    }
    try {
        const response = await listBranchStock(selectedBranchUuid.value);
        branchStock.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load stock';
    }
}

async function fetchMovements(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        movements.value = null;
        return;
    }
    try {
        movements.value = await listStockMovements(selectedBranchUuid.value, {
            ingredient: movementFilters.ingredient_uuid || undefined,
            type: movementFilters.type || undefined,
            page: movementsPage.value,
        });
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load movements';
    }
}

async function bootstrap(): Promise<void> {
    loading.value = true;
    error.value = null;
    await Promise.all([fetchBranches(), fetchIngredients(), fetchSuppliers()]);
    if (selectedBranchUuid.value !== null) {
        await Promise.all([fetchBranchStock(), fetchMovements()]);
    }
    loading.value = false;
}

onMounted(() => {
    void bootstrap();
});

// Re-fetch stock + movements when the branch picker changes.
watch(selectedBranchUuid, () => {
    void fetchBranchStock();
    void fetchMovements();
});

// Re-fetch movements when filters change.
watch(
    () => [movementFilters.ingredient_uuid, movementFilters.type, movementsPage.value],
    () => void fetchMovements(),
);

// =================== Ingredient flows ============================

function openCreateIngredient(): void {
    ingModalMode.value = 'create';
    ingModalTarget.value = null;
    ingForm.name = '';
    ingForm.name_ar = '';
    ingForm.unit = 'kg';
    ingForm.default_unit_cost = '0.000';
    ingForm.min_stock_threshold = '';
    ingForm.primary_supplier_id = null;
    ingForm.status = 'active';
    ingModalErrors.value = {};
    ingModalError.value = null;
    ingModalOpen.value = true;
}

function openEditIngredient(ingredient: Ingredient): void {
    ingModalMode.value = 'edit';
    ingModalTarget.value = ingredient;
    ingForm.name = ingredient.name;
    ingForm.name_ar = ingredient.name_ar ?? '';
    ingForm.unit = ingredient.unit;
    ingForm.default_unit_cost = ingredient.default_unit_cost;
    ingForm.min_stock_threshold = ingredient.min_stock_threshold ?? '';
    ingForm.primary_supplier_id = ingredient.primary_supplier_id;
    ingForm.status = ingredient.status;
    ingModalErrors.value = {};
    ingModalError.value = null;
    ingModalOpen.value = true;
}

async function submitIngredient(): Promise<void> {
    ingModalBusy.value = true;
    ingModalErrors.value = {};
    ingModalError.value = null;
    try {
        const payload = {
            name: ingForm.name.trim(),
            name_ar: ingForm.name_ar.trim() || null,
            unit: ingForm.unit,
            default_unit_cost: ingForm.default_unit_cost,
            min_stock_threshold: ingForm.min_stock_threshold.trim() === ''
                ? null
                : ingForm.min_stock_threshold,
            primary_supplier_id: ingForm.primary_supplier_id ?? null,
        };
        if (ingModalMode.value === 'create') {
            await createIngredient(payload);
        } else if (ingModalTarget.value) {
            await updateIngredient(ingModalTarget.value.uuid, {
                ...payload,
                status: ingForm.status,
            });
        }
        ingModalOpen.value = false;
        await fetchIngredients();
        // Refresh stock too — supplier names may have changed
        // and the stock list inlines them.
        await fetchBranchStock();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            ingModalErrors.value = err.payload.errors;
            ingModalError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            ingModalError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            ingModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        ingModalBusy.value = false;
    }
}

async function confirmDeleteIngredient(): Promise<void> {
    if (!ingDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteIngredient(ingDeleteTarget.value.uuid);
        ingDeleteTarget.value = null;
        await fetchIngredients();
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

// =================== Supplier flows ==============================

function openCreateSupplier(): void {
    supModalMode.value = 'create';
    supModalTarget.value = null;
    supForm.name = '';
    supForm.contact = '';
    supForm.notes = '';
    supForm.status = 'active';
    supModalErrors.value = {};
    supModalError.value = null;
    supModalOpen.value = true;
}

function openEditSupplier(supplier: Supplier): void {
    supModalMode.value = 'edit';
    supModalTarget.value = supplier;
    supForm.name = supplier.name;
    supForm.contact = supplier.contact ?? '';
    supForm.notes = supplier.notes ?? '';
    supForm.status = supplier.status;
    supModalErrors.value = {};
    supModalError.value = null;
    supModalOpen.value = true;
}

async function submitSupplier(): Promise<void> {
    supModalBusy.value = true;
    supModalErrors.value = {};
    supModalError.value = null;
    try {
        const payload = {
            name: supForm.name.trim(),
            contact: supForm.contact.trim() || null,
            notes: supForm.notes.trim() || null,
        };
        if (supModalMode.value === 'create') {
            await createSupplier(payload);
        } else if (supModalTarget.value) {
            await updateSupplier(supModalTarget.value.uuid, { ...payload, status: supForm.status });
        }
        supModalOpen.value = false;
        await fetchSuppliers();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            supModalErrors.value = err.payload.errors;
            supModalError.value = t('inventory.validation_summary');
        } else {
            supModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        supModalBusy.value = false;
    }
}

async function confirmDeleteSupplier(): Promise<void> {
    if (!supDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteSupplier(supDeleteTarget.value.uuid);
        supDeleteTarget.value = null;
        await fetchSuppliers();
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

// =================== Adjust / Restock flows ======================

function openAdjust(row: BranchStockRow): void {
    const ingredient = ingredients.value.find((i) => i.id === row.ingredient_id) ?? null;
    adjustTarget.value = { ingredient, row };
    adjustForm.signed_quantity = '';
    adjustForm.note = '';
    adjustErrors.value = {};
    adjustError.value = null;
    adjustOpen.value = true;
}

async function submitAdjust(): Promise<void> {
    if (selectedBranchUuid.value === null || adjustTarget.value.ingredient === null) return;
    adjustBusy.value = true;
    adjustErrors.value = {};
    adjustError.value = null;
    try {
        await adjustStock(selectedBranchUuid.value, {
            ingredient_uuid: adjustTarget.value.ingredient.uuid,
            signed_quantity: adjustForm.signed_quantity,
            note: adjustForm.note,
        });
        adjustOpen.value = false;
        await Promise.all([fetchBranchStock(), fetchMovements()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            adjustErrors.value = err.payload.errors;
            adjustError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            adjustError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            adjustError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        adjustBusy.value = false;
    }
}

function openRestock(row: BranchStockRow | null, ingredient?: Ingredient): void {
    const ing = ingredient ?? (row ? ingredients.value.find((i) => i.id === row.ingredient_id) ?? null : null);
    restockTarget.value = { ingredient: ing, row };
    restockForm.quantity = '';
    restockForm.unit_cost = '';
    restockForm.supplier_uuid = '';
    restockForm.note = '';
    restockErrors.value = {};
    restockError.value = null;
    restockOpen.value = true;
}

async function submitRestock(): Promise<void> {
    if (selectedBranchUuid.value === null || restockTarget.value.ingredient === null) return;
    restockBusy.value = true;
    restockErrors.value = {};
    restockError.value = null;
    try {
        await restockStock(selectedBranchUuid.value, {
            ingredient_uuid: restockTarget.value.ingredient.uuid,
            quantity: restockForm.quantity,
            unit_cost: restockForm.unit_cost.trim() === '' ? null : restockForm.unit_cost,
            supplier_uuid: restockForm.supplier_uuid || null,
            note: restockForm.note.trim() || null,
        });
        restockOpen.value = false;
        await Promise.all([fetchBranchStock(), fetchMovements()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            restockErrors.value = err.payload.errors;
            restockError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            restockError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            restockError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        restockBusy.value = false;
    }
}

// =================== Helpers =====================================

function unitLabel(unit: IngredientUnit | null): string {
    if (!unit) return '';
    return t(`inventory.units.${unit}`);
}

function unitShort(unit: IngredientUnit | null): string {
    if (!unit) return '';
    // Just the symbol for column displays — full label only in dropdowns.
    return unit;
}

function statusBadgeClass(status: string | null): string {
    return status === 'active'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-200 text-slate-700';
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    return t(`inventory.statuses.${status}`);
}

function healthBadgeClass(level: string): string {
    if (level === 'critical') return 'bg-rose-100 text-rose-700';
    if (level === 'low') return 'bg-amber-100 text-amber-700';
    return 'bg-emerald-100 text-emerald-700';
}

function healthLabel(level: string): string {
    return t(`inventory.health.${level}`);
}

function movementTypeLabel(type: string): string {
    return t(`inventory.movement_types.${type}`);
}

function supplierName(id: number | null): string {
    if (id === null) return t('inventory.fields.primary_supplier_none');
    const match = suppliers.value.find((s) => s.id === id);
    return match?.name ?? '—';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(isArabic.value ? 'ar-OM' : 'en-GB', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function isOutflow(qty: string): boolean {
    return parseFloat(qty) < 0;
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('inventory.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('inventory.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('inventory.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'ingredients' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'ingredients'"
                >
                    <Boxes class="size-4" />
                    {{ t('inventory.tabs.ingredients') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ ingredients.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'suppliers' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'suppliers'"
                >
                    <Users class="size-4" />
                    {{ t('inventory.tabs.suppliers') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ suppliers.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'stock' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'stock'"
                >
                    <Package class="size-4" />
                    {{ t('inventory.tabs.stock') }}
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'movements' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'movements'"
                >
                    <History class="size-4" />
                    {{ t('inventory.tabs.movements') }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- ================== INGREDIENTS TAB ================== -->
            <section v-if="activeTab === 'ingredients'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateIngredient"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.add_ingredient') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="ingredients.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Boxes class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_ingredients') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.unit') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.default_cost') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.min_threshold') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.supplier') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="ing in ingredients" :key="ing.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ ing.name }}</span>
                                    <span v-if="ing.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ ing.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ unitShort(ing.unit) }}</td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-950">{{ ing.default_unit_cost }} <span class="text-[10px] text-slate-400">OMR</span></td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-500">{{ ing.min_stock_threshold ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ ing.primary_supplier?.name ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(ing.status)">
                                        {{ statusLabel(ing.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex gap-2">
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditIngredient(ing)">
                                            <Pencil class="size-3" /> {{ t('inventory.actions.edit') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="ingDeleteTarget = ing">
                                            <Trash2 class="size-3" /> {{ t('inventory.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================== SUPPLIERS TAB ================== -->
            <section v-if="activeTab === 'suppliers'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateSupplier"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.add_supplier') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="suppliers.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Users class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_suppliers') }}</p>
                </div>
                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="sup in suppliers" :key="sup.id" class="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-200">
                        <header class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ sup.name }}</h2>
                                <p v-if="sup.contact" class="text-xs text-slate-500">{{ sup.contact }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(sup.status)">
                                {{ statusLabel(sup.status) }}
                            </span>
                        </header>
                        <p v-if="sup.notes" class="text-xs text-slate-600">{{ sup.notes }}</p>
                        <p class="text-xs text-slate-500">{{ t('inventory.table.ingredients') }}: {{ sup.ingredients_count ?? 0 }}</p>
                        <div v-if="canManage" class="mt-auto flex gap-1.5 pt-2">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditSupplier(sup)">
                                <Pencil class="size-3" /> {{ t('inventory.actions.edit') }}
                            </button>
                            <button
                                type="button"
                                :disabled="(sup.ingredients_count ?? 0) > 0"
                                :title="(sup.ingredients_count ?? 0) > 0 ? t('inventory.delete_supplier_blocked') : ''"
                                class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="supDeleteTarget = sup"
                            >
                                <Trash2 class="size-3" /> {{ t('inventory.actions.delete') }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ================== STOCK TAB ================== -->
            <section v-if="activeTab === 'stock' || activeTab === 'movements'" class="space-y-4">
                <!-- Branch picker — shared by stock + movements -->
                <label class="block max-w-md">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <Building2 class="me-1 inline size-3" />
                        {{ t('inventory.branch') }}
                    </span>
                    <select v-model="selectedBranchUuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="branch in branches" :key="branch.uuid" :value="branch.uuid">
                            {{ branch.name }}
                        </option>
                    </select>
                </label>

                <div v-if="branches.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Building2 class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-medium text-slate-600">{{ t('inventory.no_branches') }}</p>
                </div>
            </section>

            <section v-if="activeTab === 'stock' && branches.length > 0" class="space-y-4">
                <div v-if="canManage && ingredients.length > 0" class="flex justify-end">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                        @click="openRestock(null, ingredients[0])"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.restock') }}
                    </button>
                </div>

                <div v-if="branchStock.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Package class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_stock') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.quantity') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.health') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.last_movement') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in branchStock" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.ingredient?.name ?? '—' }}</span>
                                    <span v-if="row.ingredient?.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ row.ingredient.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums text-slate-950">
                                    {{ row.quantity }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ unitShort(row.ingredient?.unit ?? null) }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="healthBadgeClass(row.health_level)">
                                        {{ healthLabel(row.health_level) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDate(row.last_movement_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div v-if="canManage" class="inline-flex gap-2">
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openAdjust(row)">
                                            <Minus class="size-3" /> {{ t('inventory.actions.adjust') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100" @click="openRestock(row)">
                                            <Plus class="size-3" /> {{ t('inventory.actions.restock') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================== MOVEMENTS TAB ================== -->
            <section v-if="activeTab === 'movements' && branches.length > 0" class="space-y-4">
                <div class="flex flex-wrap gap-3">
                    <label class="block min-w-xs flex-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.movements_filter.ingredient') }}</span>
                        <select v-model="movementFilters.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.movements_filter.all_ingredients') }}</option>
                            <option v-for="ing in ingredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }}</option>
                        </select>
                    </label>
                    <label class="block min-w-xs flex-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.movements_filter.type') }}</span>
                        <select v-model="movementFilters.type" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.movements_filter.all_types') }}</option>
                            <option value="initial">{{ t('inventory.movement_types.initial') }}</option>
                            <option value="restock">{{ t('inventory.movement_types.restock') }}</option>
                            <option value="adjustment">{{ t('inventory.movement_types.adjustment') }}</option>
                            <option value="waste">{{ t('inventory.movement_types.waste') }}</option>
                            <option value="loss">{{ t('inventory.movement_types.loss') }}</option>
                            <option value="sale_consumption">{{ t('inventory.movement_types.sale_consumption') }}</option>
                            <option value="addon_consumption">{{ t('inventory.movement_types.addon_consumption') }}</option>
                            <option value="transfer_in">{{ t('inventory.movement_types.transfer_in') }}</option>
                            <option value="transfer_out">{{ t('inventory.movement_types.transfer_out') }}</option>
                        </select>
                    </label>
                </div>

                <div v-if="movements && movements.data.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <History class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_movements') }}</p>
                </div>
                <div v-else-if="movements" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.when') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.type') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.fields.ingredient') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.qty') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.unit_cost') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.note') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.by') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="m in movements.data" :key="m.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-xs text-slate-500 whitespace-nowrap">{{ formatDate(m.occurred_at) }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700">
                                        {{ movementTypeLabel(m.movement_type) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-900">{{ m.ingredient?.name ?? '—' }}</td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums" :class="isOutflow(m.quantity) ? 'text-rose-700' : 'text-emerald-700'">
                                    {{ m.quantity }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ unitShort(m.ingredient?.unit ?? null) }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-xs tabular-nums text-slate-500">{{ m.unit_cost_at_time }} <span class="text-[10px] text-slate-400">OMR</span></td>
                                <td class="px-5 py-4 text-xs text-slate-600 max-w-xs truncate" :title="m.note ?? ''">{{ m.note ?? '—' }}</td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ m.recorded_by?.name ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Pagination -->
                    <div v-if="movements.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-600">
                        <span>{{ movements.meta.current_page }} / {{ movements.meta.last_page }} ({{ movements.meta.total }})</span>
                        <div class="flex gap-1">
                            <button type="button" :disabled="movements.meta.current_page <= 1" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="movementsPage = Math.max(1, movements.meta.current_page - 1)">‹</button>
                            <button type="button" :disabled="movements.meta.current_page >= movements.meta.last_page" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="movementsPage = Math.min(movements.meta.last_page, movements.meta.current_page + 1)">›</button>
                        </div>
                    </div>
                </div>
            </section>
        </section>

        <!-- ================== INGREDIENT MODAL ================== -->
        <div v-if="ingModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ ingModalMode === 'create' ? t('inventory.ing_modal.create_title') : t('inventory.ing_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitIngredient">
                    <div v-if="ingModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ ingModalError }}
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name') }} *</span>
                            <input v-model="ingForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="ingModalErrors.name" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.name[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name_ar') }}</span>
                            <input v-model="ingForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.unit') }} *</span>
                            <select v-model="ingForm.unit" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="u in unitOptions" :key="u" :value="u">{{ unitLabel(u) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.default_unit_cost') }} (OMR)</span>
                            <input v-model="ingForm.default_unit_cost" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.min_stock_threshold') }}</span>
                            <input v-model="ingForm.min_stock_threshold" type="number" step="0.001" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.min_stock_threshold_hint') }}</p>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <Truck class="me-1 inline size-3" />
                            {{ t('inventory.fields.primary_supplier') }}
                        </span>
                        <select v-model="ingForm.primary_supplier_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="null">{{ t('inventory.fields.primary_supplier_none') }}</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
                        </select>
                    </label>
                    <label v-if="ingModalMode === 'edit'" class="block max-w-xs">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.status') }}</span>
                        <select v-model="ingForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('inventory.statuses.active') }}</option>
                            <option value="inactive">{{ t('inventory.statuses.inactive') }}</option>
                        </select>
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="ingModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ ingModalBusy ? t('inventory.ing_modal.submitting') : t('inventory.ing_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== SUPPLIER MODAL ================== -->
        <div v-if="supModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ supModalMode === 'create' ? t('inventory.sup_modal.create_title') : t('inventory.sup_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitSupplier">
                    <div v-if="supModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ supModalError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name') }} *</span>
                        <input v-model="supForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="supModalErrors.name" class="mt-1 text-xs text-rose-600">{{ supModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.contact') }}</span>
                        <input v-model="supForm.contact" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                        <textarea v-model="supForm.notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                    </label>
                    <label v-if="supModalMode === 'edit'" class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.status') }}</span>
                        <select v-model="supForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('inventory.statuses.active') }}</option>
                            <option value="inactive">{{ t('inventory.statuses.inactive') }}</option>
                        </select>
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="supModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ supModalBusy ? t('inventory.sup_modal.submitting') : t('inventory.sup_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== ADJUST MODAL ================== -->
        <div v-if="adjustOpen && adjustTarget.ingredient" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.adjust_modal.title', { ingredient: adjustTarget.ingredient.name }) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ t('inventory.adjust_modal.subtitle') }}</p>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitAdjust">
                    <div v-if="adjustError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ adjustError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            {{ t('inventory.fields.signed_quantity') }} ({{ unitShort(adjustTarget.ingredient.unit) }}) *
                        </span>
                        <input v-model="adjustForm.signed_quantity" required type="number" step="0.001" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.signed_quantity_hint') }}</p>
                        <p v-if="adjustErrors.signed_quantity" class="mt-1 text-xs text-rose-600">{{ adjustErrors.signed_quantity[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.note_required') }} *</span>
                        <textarea v-model="adjustForm.note" required rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.note_required_hint') }}</p>
                        <p v-if="adjustErrors.note" class="mt-1 text-xs text-rose-600">{{ adjustErrors.note[0] }}</p>
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="adjustOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="adjustBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ adjustBusy ? t('inventory.adjust_modal.submitting') : t('inventory.adjust_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== RESTOCK MODAL ================== -->
        <div v-if="restockOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock_modal.title', { ingredient: restockTarget.ingredient?.name ?? '' }) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ t('inventory.restock_modal.subtitle') }}</p>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitRestock">
                    <div v-if="restockError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ restockError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.ingredient') }} *</span>
                        <select v-model="restockTarget.ingredient" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option v-for="ing in ingredients" :key="ing.id" :value="ing">{{ ing.name }}</option>
                        </select>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.quantity') }} ({{ unitShort(restockTarget.ingredient?.unit ?? null) }}) *</span>
                            <input v-model="restockForm.quantity" required type="number" step="0.001" min="0.001" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="restockErrors.quantity" class="mt-1 text-xs text-rose-600">{{ restockErrors.quantity[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.unit_cost_override') }}</span>
                            <input v-model="restockForm.unit_cost" type="number" step="0.001" min="0" :placeholder="restockTarget.ingredient?.default_unit_cost ?? '—'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.unit_cost_hint') }}</p>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <Truck class="me-1 inline size-3" />
                            {{ t('inventory.fields.supplier') }}
                        </span>
                        <select v-model="restockForm.supplier_uuid" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.fields.primary_supplier_none') }}</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.uuid">{{ sup.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                        <textarea v-model="restockForm.note" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="restockOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="restockBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ restockBusy ? t('inventory.restock_modal.submitting') : t('inventory.restock_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== DELETE CONFIRMS ================== -->
        <div v-if="ingDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.delete_ing_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('inventory.delete_ing_dialog.body', { name: ingDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteIngredient">
                        {{ deleting ? t('inventory.delete_ing_dialog.submitting') : t('inventory.delete_ing_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="supDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.delete_sup_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('inventory.delete_sup_dialog.body', { name: supDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteSupplier">
                        {{ deleting ? t('inventory.delete_sup_dialog.submitting') : t('inventory.delete_sup_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

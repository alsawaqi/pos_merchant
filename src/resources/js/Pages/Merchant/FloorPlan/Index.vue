<script setup lang="ts">
/**
 * Floor Plan — manage floors + tables per branch.
 *
 * Phase 5 (flat list version). Branch selector at top
 * defaults to the first branch the merchant owns. Below it,
 * each floor is a card with its tables shown as a grid. The
 * "Add floor" / "Add table" buttons + per-row Edit / Delete
 * are gated on FloorPlanManage.
 *
 * Visual drag-and-drop floor planner deferred to Phase 5.5
 * — this page covers 95% of the operational value (orders
 * bind to tables, kitchen knows which table to deliver to,
 * scan-to-order QR codes per table).
 *
 * Permission gating:
 *   - Page reachable when MerchantPermission.FloorPlanView
 *   - Add / edit / delete only when FloorPlanManage
 */

import {
    BarChart3,
    Building2,
    Copy,
    LayoutGrid,
    Move,
    Pencil,
    Plus,
    QrCode,
    RefreshCw,
    Trash2,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import FloorPlanner from '@/Pages/Merchant/FloorPlan/FloorPlanner.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    createFloor,
    createTable,
    deleteFloor,
    deleteTable,
    listFloors,
    regenerateTableQr,
    updateFloor,
    updateTable,
    type CreateTablePayload,
    type Floor,
    type MerchantTable,
    type TableShape,
} from '@/lib/api/floorPlan';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.FloorPlanManage));
// Reports.view unlocks the per-table insights drill-down (separate gate).
const canViewInsights = computed(() => can(MerchantPermission.ReportsView));

// ---- Branch selector + data ------------------------------------
const branches = ref<Branch[]>([]);
const selectedBranchUuid = ref<string | null>(null);
const floors = ref<Floor[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

// ---- Floor modal -----------------------------------------------
const floorModalOpen = ref(false);
const floorModalBusy = ref(false);
const floorModalMode = ref<'create' | 'edit'>('create');
const floorModalTarget = ref<Floor | null>(null);
const floorModalErrors = ref<Record<string, string[]>>({});
const floorModalError = ref<string | null>(null);
const floorForm = reactive<{
    name: string;
    name_ar: string;
    display_order: number;
}>({ name: '', name_ar: '', display_order: 0 });

// ---- Table modal -----------------------------------------------
const tableModalOpen = ref(false);
const tableModalBusy = ref(false);
const tableModalMode = ref<'create' | 'edit'>('create');
const tableModalTargetFloor = ref<Floor | null>(null);
const tableModalTarget = ref<MerchantTable | null>(null);
const tableModalErrors = ref<Record<string, string[]>>({});
const tableModalError = ref<string | null>(null);
const tableForm = reactive<{
    label: string;
    seats: number;
    min_party: number | null;
    max_party: number | null;
    shape: TableShape;
    notes: string;
    display_order: number;
}>({
    label: '',
    seats: 4,
    min_party: null,
    max_party: null,
    shape: 'square',
    notes: '',
    display_order: 0,
});

// ---- QR modal --------------------------------------------------
const qrModalOpen = ref(false);
const qrModalTable = ref<MerchantTable | null>(null);
const qrCopied = ref(false);

// ---- Delete confirms -------------------------------------------
const floorDeleteTarget = ref<Floor | null>(null);
const tableDeleteTarget = ref<MerchantTable | null>(null);
const deleting = ref(false);

// ---- Phase 5.5 planner -----------------------------------------
// One floor at a time can be in planner mode. NULL = nobody.
// When set, that floor card swaps its tables grid for the
// FloorPlanner canvas.
const plannerFloorUuid = ref<string | null>(null);

function openPlanner(floor: Floor): void {
    plannerFloorUuid.value = floor.uuid;
}

async function onPlannerSaved(): Promise<void> {
    // Reordering persisted — refresh so the new table order reflects in the
    // management grid (and on the POS at its next config fetch). Planner stays
    // open so the merchant can keep arranging.
    await fetchFloors();
}

function onPlannerClose(): void {
    plannerFloorUuid.value = null;
}

const shapeOptions: { value: TableShape; key: string }[] = [
    { value: 'round', key: 'round' },
    { value: 'square', key: 'square' },
    { value: 'rectangle', key: 'rectangle' },
    { value: 'oval', key: 'oval' },
    { value: 'counter', key: 'counter' },
];

// ---- Fetch -----------------------------------------------------
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

async function fetchFloors(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        floors.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const response = await listFloors(selectedBranchUuid.value);
        floors.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load floors';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await fetchBranches();
    await fetchFloors();
});

watch(selectedBranchUuid, () => {
    void fetchFloors();
});

// ---- Floor flows ------------------------------------------------

function openCreateFloor(): void {
    floorModalMode.value = 'create';
    floorModalTarget.value = null;
    floorForm.name = '';
    floorForm.name_ar = '';
    floorForm.display_order = floors.value.length;
    floorModalErrors.value = {};
    floorModalError.value = null;
    floorModalOpen.value = true;
}

function openEditFloor(floor: Floor): void {
    floorModalMode.value = 'edit';
    floorModalTarget.value = floor;
    floorForm.name = floor.name;
    floorForm.name_ar = floor.name_ar ?? '';
    floorForm.display_order = floor.display_order;
    floorModalErrors.value = {};
    floorModalError.value = null;
    floorModalOpen.value = true;
}

async function submitFloor(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    floorModalBusy.value = true;
    floorModalErrors.value = {};
    floorModalError.value = null;
    try {
        const payload = {
            name: floorForm.name.trim(),
            name_ar: floorForm.name_ar.trim() || null,
            display_order: floorForm.display_order,
        };
        if (floorModalMode.value === 'create') {
            await createFloor(selectedBranchUuid.value, payload);
        } else if (floorModalTarget.value) {
            await updateFloor(floorModalTarget.value.uuid, payload);
        }
        floorModalOpen.value = false;
        await fetchFloors();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            floorModalErrors.value = err.payload.errors;
            floorModalError.value = t('floor_plan.validation_summary');
        } else {
            floorModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        floorModalBusy.value = false;
    }
}

async function confirmDeleteFloor(): Promise<void> {
    if (!floorDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteFloor(floorDeleteTarget.value.uuid);
        floorDeleteTarget.value = null;
        await fetchFloors();
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

// ---- Table flows -----------------------------------------------

function openCreateTable(floor: Floor): void {
    tableModalMode.value = 'create';
    tableModalTargetFloor.value = floor;
    tableModalTarget.value = null;
    tableForm.label = '';
    tableForm.seats = 4;
    tableForm.min_party = null;
    tableForm.max_party = null;
    tableForm.shape = 'square';
    tableForm.notes = '';
    tableForm.display_order = floor.tables.length;
    tableModalErrors.value = {};
    tableModalError.value = null;
    tableModalOpen.value = true;
}

function openEditTable(floor: Floor, table: MerchantTable): void {
    tableModalMode.value = 'edit';
    tableModalTargetFloor.value = floor;
    tableModalTarget.value = table;
    tableForm.label = table.label;
    tableForm.seats = table.seats;
    tableForm.min_party = table.min_party;
    tableForm.max_party = table.max_party;
    tableForm.shape = (table.shape ?? 'square') as TableShape;
    tableForm.notes = table.notes ?? '';
    tableForm.display_order = table.display_order;
    tableModalErrors.value = {};
    tableModalError.value = null;
    tableModalOpen.value = true;
}

async function submitTable(): Promise<void> {
    tableModalBusy.value = true;
    tableModalErrors.value = {};
    tableModalError.value = null;
    try {
        const payload: CreateTablePayload = {
            label: tableForm.label.trim(),
            seats: tableForm.seats,
            min_party: tableForm.min_party,
            max_party: tableForm.max_party,
            shape: tableForm.shape,
            notes: tableForm.notes.trim() || null,
            display_order: tableForm.display_order,
        };
        if (tableModalMode.value === 'create' && tableModalTargetFloor.value) {
            await createTable(tableModalTargetFloor.value.uuid, payload);
        } else if (tableModalTarget.value) {
            await updateTable(tableModalTarget.value.uuid, payload);
        }
        tableModalOpen.value = false;
        await fetchFloors();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            tableModalErrors.value = err.payload.errors;
            tableModalError.value = t('floor_plan.validation_summary');
        } else {
            tableModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        tableModalBusy.value = false;
    }
}

async function confirmDeleteTable(): Promise<void> {
    if (!tableDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteTable(tableDeleteTarget.value.uuid);
        tableDeleteTarget.value = null;
        await fetchFloors();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed';
    } finally {
        deleting.value = false;
    }
}

// ---- QR flows --------------------------------------------------

function openQrModal(table: MerchantTable): void {
    qrModalTable.value = table;
    qrCopied.value = false;
    qrModalOpen.value = true;
}

async function copyQrToken(): Promise<void> {
    if (!qrModalTable.value) return;
    try {
        await navigator.clipboard.writeText(qrModalTable.value.qr_token);
        qrCopied.value = true;
        window.setTimeout(() => { qrCopied.value = false; }, 2000);
    } catch {
        const el = document.getElementById('floor-plan-qr-out');
        if (el instanceof HTMLInputElement) el.select();
    }
}

async function rotateQrToken(): Promise<void> {
    if (!qrModalTable.value) return;
    try {
        const response = await regenerateTableQr(qrModalTable.value.uuid);
        qrModalTable.value = response.data;
        qrCopied.value = false;
        await fetchFloors();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed';
    }
}

function shapeLabel(shape: TableShape | null): string {
    if (!shape) return '—';
    return t(`floor_plan.shapes.${shape}`);
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    return t(`floor_plan.statuses.${status}`);
}

function statusBadgeClass(status: string | null): string {
    return status === 'active'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-200 text-slate-700';
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('floor_plan.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('floor_plan.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('floor_plan.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Branch picker + Add floor button -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <label class="block max-w-md">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <Building2 class="me-1 inline size-3" />
                        {{ t('floor_plan.branch') }}
                    </span>
                    <select v-model="selectedBranchUuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="branch in branches" :key="branch.uuid" :value="branch.uuid">
                            {{ branch.name }}
                        </option>
                    </select>
                </label>

                <button
                    v-if="canManage && selectedBranchUuid"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                    @click="openCreateFloor"
                >
                    <Plus class="size-4" />
                    {{ t('floor_plan.actions.add_floor') }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Empty state for branches -->
            <div v-if="branches.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                <Building2 class="mx-auto size-10 text-slate-300" />
                <p class="mt-3 text-sm font-medium text-slate-600">{{ t('floor_plan.no_branches') }}</p>
            </div>

            <!-- Loading -->
            <section v-else-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('common.loading') }}
            </section>

            <!-- Empty state for floors -->
            <section v-else-if="floors.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                <LayoutGrid class="mx-auto size-10 text-slate-300" />
                <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('floor_plan.empty_state') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ t('floor_plan.empty_hint') }}</p>
            </section>

            <!-- Floors + tables -->
            <section v-else class="space-y-6">
                <article
                    v-for="floor in floors"
                    :key="floor.id"
                    class="rounded-2xl border border-slate-200 bg-white shadow-sm"
                >
                    <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">{{ floor.name }}</h2>
                                <span v-if="floor.name_ar" class="text-sm text-slate-500">/ {{ floor.name_ar }}</span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(floor.status)">
                                    {{ statusLabel(floor.status) }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-500">{{ t('floor_plan.table_count', { count: floor.tables_count }) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <!-- Planner toggle — visible to anyone with
                                 FloorPlanView (the canvas itself
                                 gates its drag interactions on
                                 canManage). -->
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="floor.tables_count === 0"
                                :title="floor.tables_count === 0 ? t('floor_plan.planner.no_tables_hint') : ''"
                                @click="openPlanner(floor)"
                            >
                                <Move class="size-3.5" />
                                {{ plannerFloorUuid === floor.uuid ? t('floor_plan.planner.viewing') : t('floor_plan.actions.open_planner') }}
                            </button>
                            <template v-if="canManage">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                    @click="openCreateTable(floor)"
                                >
                                    <Plus class="size-3.5" />
                                    {{ t('floor_plan.actions.add_table') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                    @click="openEditFloor(floor)"
                                >
                                    <Pencil class="size-3.5" />
                                    {{ t('floor_plan.actions.edit_floor') }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="floor.tables_count > 0"
                                    :title="floor.tables_count > 0 ? t('floor_plan.delete_floor_blocked') : ''"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="floorDeleteTarget = floor"
                                >
                                    <Trash2 class="size-3.5" />
                                    {{ t('floor_plan.actions.delete_floor') }}
                                </button>
                            </template>
                        </div>
                    </header>

                    <!-- Phase 5.5 — planner mode replaces the grid
                         for the currently-active floor. The list
                         view continues to render for every other
                         floor on the page. -->
                    <div v-if="plannerFloorUuid === floor.uuid" class="p-0">
                        <FloorPlanner
                            :floor="floor"
                            :can-manage="canManage"
                            @close="onPlannerClose"
                            @saved="onPlannerSaved"
                        />
                    </div>
                    <div v-else class="p-5">
                        <div v-if="floor.tables.length === 0" class="rounded-lg border border-dashed border-slate-200 p-8 text-center text-sm italic text-slate-500">
                            {{ t('floor_plan.no_tables') }}
                        </div>
                        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <article
                                v-for="table in floor.tables"
                                :key="table.id"
                                class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50/30 p-4 transition hover:border-teal-200"
                            >
                                <header class="flex items-start justify-between">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-950">{{ table.label }}</h3>
                                        <p class="text-xs text-slate-500">{{ shapeLabel(table.shape) }} · {{ t('floor_plan.seats_n', { count: table.seats }) }}</p>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(table.status)">
                                        {{ statusLabel(table.status) }}
                                    </span>
                                </header>
                                <p v-if="table.notes" class="text-xs text-slate-600">{{ table.notes }}</p>
                                <p v-if="table.min_party !== null || table.max_party !== null" class="text-xs text-slate-500">
                                    {{ t('floor_plan.party_range', {
                                        min: table.min_party ?? '–',
                                        max: table.max_party ?? '–',
                                    }) }}
                                </p>
                                <div v-if="canManage || canViewInsights" class="mt-auto flex flex-wrap items-center gap-1.5 pt-2">
                                    <RouterLink
                                        v-if="canViewInsights"
                                        :to="`/tables/${table.uuid}`"
                                        class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100"
                                    >
                                        <BarChart3 class="size-3" />
                                        {{ t('tables.view_insights') }}
                                    </RouterLink>
                                    <template v-if="canManage">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-white"
                                            @click="openEditTable(floor, table)"
                                        >
                                            <Pencil class="size-3" />
                                            {{ t('floor_plan.actions.edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-white"
                                            @click="openQrModal(table)"
                                        >
                                            <QrCode class="size-3" />
                                            {{ t('floor_plan.actions.qr') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50"
                                            @click="tableDeleteTarget = table"
                                        >
                                            <Trash2 class="size-3" />
                                            {{ t('floor_plan.actions.delete') }}
                                        </button>
                                    </template>
                                </div>
                            </article>
                        </div>
                    </div>
                </article>
            </section>
        </section>

        <!-- ================= FLOOR MODAL ================== -->
        <BaseModal
            v-if="floorModalOpen"
            :title="floorModalMode === 'create' ? t('floor_plan.floor_modal.create_title') : t('floor_plan.floor_modal.edit_title')"
            size="md"
            :loading="floorModalBusy"
            @close="floorModalOpen = false"
        >
            <form id="floor-modal-form" class="space-y-4" @submit.prevent="submitFloor">
                <div v-if="floorModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ floorModalError }}
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.floor_name') }} *</span>
                    <input v-model="floorForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="floorModalErrors.name" class="mt-1 text-xs text-rose-600">{{ floorModalErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.floor_name_ar') }}</span>
                    <input v-model="floorForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.display_order') }}</span>
                    <input v-model.number="floorForm.display_order" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="floorModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="floor-modal-form" :disabled="floorModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ floorModalBusy ? t('floor_plan.floor_modal.submitting') : t('floor_plan.floor_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= TABLE MODAL ================== -->
        <BaseModal
            v-if="tableModalOpen"
            size="lg"
            :loading="tableModalBusy"
            @close="tableModalOpen = false"
        >
            <template #header>
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ tableModalMode === 'create' ? t('floor_plan.table_modal.create_title') : t('floor_plan.table_modal.edit_title') }}
                    </h2>
                    <p v-if="tableModalTargetFloor" class="mt-1 text-xs text-slate-500">{{ tableModalTargetFloor.name }}</p>
                </div>
            </template>
            <form id="table-modal-form" class="space-y-4" @submit.prevent="submitTable">
                <div v-if="tableModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ tableModalError }}
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.label') }} *</span>
                        <input v-model="tableForm.label" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="tableModalErrors.label" class="mt-1 text-xs text-rose-600">{{ tableModalErrors.label[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.seats') }}</span>
                        <input v-model.number="tableForm.seats" type="number" min="1" max="99" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.min_party') }}</span>
                        <input v-model.number="tableForm.min_party" type="number" min="1" max="99" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.max_party') }}</span>
                        <input v-model.number="tableForm.max_party" type="number" min="1" max="99" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="tableModalErrors.max_party" class="mt-1 text-xs text-rose-600">{{ tableModalErrors.max_party[0] }}</p>
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.shape') }}</span>
                    <select v-model="tableForm.shape" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in shapeOptions" :key="opt.value" :value="opt.value">{{ t(`floor_plan.shapes.${opt.key}`) }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('floor_plan.fields.notes') }}</span>
                    <textarea v-model="tableForm.notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="tableModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="table-modal-form" :disabled="tableModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ tableModalBusy ? t('floor_plan.table_modal.submitting') : t('floor_plan.table_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= QR MODAL ================== -->
        <BaseModal
            v-if="qrModalOpen && qrModalTable"
            size="md"
            @close="qrModalOpen = false"
        >
            <template #header>
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('floor_plan.qr_modal.title', { label: qrModalTable.label }) }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ t('floor_plan.qr_modal.subtitle') }}</p>
                </div>
            </template>
            <div class="space-y-4">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('floor_plan.qr_modal.token_label') }}</span>
                    <div class="mt-2 flex gap-2">
                        <input id="floor-plan-qr-out" :value="qrModalTable.qr_token" readonly class="flex-1 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono tracking-wider text-slate-950 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-semibold transition" :class="qrCopied ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-700 hover:bg-slate-50'" @click="copyQrToken">
                            <Copy class="size-4" />
                            {{ qrCopied ? t('floor_plan.qr_modal.copied') : t('floor_plan.qr_modal.copy') }}
                        </button>
                    </div>
                </label>
                <p class="text-xs text-slate-500">{{ t('floor_plan.qr_modal.menu_url_hint') }}</p>
            </div>
            <template #footer>
                <div class="flex justify-between gap-2">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-700 transition hover:bg-amber-50"
                        @click="rotateQrToken"
                    >
                        <RefreshCw class="size-3.5" />
                        {{ t('floor_plan.qr_modal.regenerate') }}
                    </button>
                    <button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800" @click="qrModalOpen = false">
                        {{ t('floor_plan.qr_modal.done') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= DELETE CONFIRMS ================== -->
        <BaseModal
            v-if="floorDeleteTarget"
            :title="t('floor_plan.delete_floor_dialog.title')"
            size="md"
            :loading="deleting"
            @close="floorDeleteTarget = null"
        >
            <div class="text-sm text-slate-700">{{ t('floor_plan.delete_floor_dialog.body', { name: floorDeleteTarget.name }) }}</div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="floorDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteFloor">
                        {{ deleting ? t('floor_plan.delete_floor_dialog.submitting') : t('floor_plan.delete_floor_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <BaseModal
            v-if="tableDeleteTarget"
            :title="t('floor_plan.delete_table_dialog.title')"
            size="md"
            :loading="deleting"
            @close="tableDeleteTarget = null"
        >
            <div class="text-sm text-slate-700">{{ t('floor_plan.delete_table_dialog.body', { label: tableDeleteTarget.label }) }}</div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="tableDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteTable">
                        {{ deleting ? t('floor_plan.delete_table_dialog.submitting') : t('floor_plan.delete_table_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

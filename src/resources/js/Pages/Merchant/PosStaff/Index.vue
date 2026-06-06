<script setup lang="ts">
/**
 * POS Staff — the PIN-authenticated workforce.
 *
 * Phase 4.6. Hire / list / edit / suspend / reactivate / terminate /
 * reset PIN. Server mints a 6-digit numeric PIN on create + reset;
 * we surface it ONCE in a copy-to-clipboard modal.
 *
 * Permission gating:
 *   - Page visible when MerchantPermission.PosStaffView
 *   - "Hire teammate" only when PosStaffCreate
 *   - Edit + Reset PIN only when PosStaffUpdate
 *   - Suspend / Reactivate / Terminate only when PosStaffRevoke
 *
 * Termination is the only destructive action that gets a confirm
 * dialog — it soft-deletes the row and removes them from the
 * roster permanently. Suspend / Reactivate are reversible flips,
 * no confirm.
 */

import { Copy, KeyRound, Pencil, Plus, RotateCw, ShieldCheck, ShieldOff, UserMinus, Users } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createPosStaff,
    listPosStaff,
    reactivatePosStaff,
    resetPosStaffPin,
    suspendPosStaff,
    terminatePosStaff,
    updatePosStaff,
    type CreatePosStaffPayload,
    type PosStaff,
} from '@/lib/api/posStaff';
import { listBranches, type Branch } from '@/lib/api/branches';
import { MerchantPermission } from '@/lib/permissions';
import { StaffPosition, StaffStatus, type StaffPositionValue, type StaffStatusValue } from '@/lib/staff';

const { t, locale } = useI18n();
const { can } = usePermissions();

// ---- Table state -------------------------------------------------
const staff = ref<PosStaff[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');

// Per-row spinner so a click on row 7's "Reset PIN" only spins row 7.
const rowBusy = ref<Record<number, boolean>>({});

// ---- Branches (for branch picker) -------------------------------
const branches = ref<Branch[]>([]);

// ---- Create modal -----------------------------------------------
const createOpen = ref(false);
const creating = ref(false);
const createFieldErrors = ref<Record<string, string[]>>({});
const createError = ref<string | null>(null);
const createForm = reactive<CreatePosStaffPayload>({
    name: '',
    branch_id: 0,
    position: StaffPosition.Cashier as StaffPositionValue,
    phone: '',
    staff_code: '',
    hired_at: null,
});

// ---- One-shot PIN modal -----------------------------------------
const pinModalOpen = ref(false);
const pinModalStaff = ref<PosStaff | null>(null);
const pinModalSecret = ref('');
const pinCopied = ref(false);

// ---- Edit modal -------------------------------------------------
const editOpen = ref(false);
const editing = ref(false);
const editFieldErrors = ref<Record<string, string[]>>({});
const editError = ref<string | null>(null);
const editTarget = ref<PosStaff | null>(null);
const editForm = reactive<{
    name: string;
    branch_id: number;
    position: StaffPositionValue;
    phone: string;
    staff_code: string;
    hired_at: string;
}>({
    name: '',
    branch_id: 0,
    position: StaffPosition.Cashier as StaffPositionValue,
    phone: '',
    staff_code: '',
    hired_at: '',
});

// ---- Terminate confirm dialog -----------------------------------
const terminateTarget = ref<PosStaff | null>(null);
const terminating = ref(false);

// ---- Catalogues -------------------------------------------------

const positionOptions: { value: StaffPositionValue; key: string }[] = [
    { value: StaffPosition.Cashier as StaffPositionValue, key: 'cashier' },
    { value: StaffPosition.Waiter as StaffPositionValue, key: 'waiter' },
    { value: StaffPosition.Kitchen as StaffPositionValue, key: 'kitchen' },
    { value: StaffPosition.Supervisor as StaffPositionValue, key: 'supervisor' },
    { value: StaffPosition.Manager as StaffPositionValue, key: 'manager' },
];

function positionLabel(position: StaffPositionValue | null | undefined): string {
    if (!position) return '—';
    const opt = positionOptions.find((p) => p.value === position);
    return opt ? t(`pos_staff.positions.${opt.key}`) : position;
}

function statusLabel(status: StaffStatusValue | null): string {
    if (!status) return '—';
    return t(`pos_staff.statuses.${status}`);
}

function statusBadgeClass(status: StaffStatusValue | null): string {
    switch (status) {
        case StaffStatus.Active: return 'bg-emerald-100 text-emerald-700';
        case StaffStatus.Suspended: return 'bg-amber-100 text-amber-700';
        case StaffStatus.Terminated: return 'bg-slate-200 text-slate-700';
        default: return 'bg-slate-100 text-slate-700';
    }
}

function formatTimestamp(iso: string | null): string {
    if (!iso) return t('pos_staff.never');
    try {
        const date = new Date(iso);
        return date.toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
            year: 'numeric', month: 'short', day: '2-digit',
        });
    } catch {
        return iso;
    }
}

// ---- Filtered list ----------------------------------------------
const filteredStaff = computed<PosStaff[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') return staff.value;
    return staff.value.filter((s) =>
        s.name.toLowerCase().includes(term)
        || (s.staff_code ?? '').toLowerCase().includes(term)
        || (s.branch.name ?? '').toLowerCase().includes(term),
    );
});

// ---- Fetchers ---------------------------------------------------
async function fetchStaff(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listPosStaff();
        staff.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load staff';
    } finally {
        loading.value = false;
    }
}

async function fetchBranches(): Promise<void> {
    try {
        const response = await listBranches();
        branches.value = response.data;
    } catch {
        branches.value = [];
    }
}

onMounted(() => {
    void fetchStaff();
    void fetchBranches();
});

// ---- Create flow ------------------------------------------------

function openCreate(): void {
    createForm.name = '';
    createForm.branch_id = branches.value[0]?.id ?? 0;
    createForm.position = StaffPosition.Cashier as StaffPositionValue;
    createForm.phone = '';
    createForm.staff_code = '';
    createForm.hired_at = null;
    createFieldErrors.value = {};
    createError.value = null;
    createOpen.value = true;
}

async function submitCreate(): Promise<void> {
    creating.value = true;
    createFieldErrors.value = {};
    createError.value = null;
    try {
        const response = await createPosStaff({
            name: createForm.name,
            branch_id: createForm.branch_id,
            position: createForm.position,
            phone: createForm.phone || null,
            staff_code: createForm.staff_code || null,
            hired_at: createForm.hired_at || null,
        });
        createOpen.value = false;
        pinModalStaff.value = response.data;
        pinModalSecret.value = response.plaintext_pin;
        pinCopied.value = false;
        pinModalOpen.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            createFieldErrors.value = err.payload.errors;
            createError.value = t('pos_staff.validation_summary');
        } else {
            createError.value = err instanceof Error ? err.message : 'Create failed';
        }
    } finally {
        creating.value = false;
    }
}

async function copyPin(): Promise<void> {
    if (!pinModalSecret.value) return;
    try {
        await navigator.clipboard.writeText(pinModalSecret.value);
        pinCopied.value = true;
        window.setTimeout(() => { pinCopied.value = false; }, 2000);
    } catch {
        const el = document.getElementById('pos-staff-pin-out');
        if (el instanceof HTMLInputElement) el.select();
    }
}

function closePinModal(): void {
    pinModalOpen.value = false;
    pinModalStaff.value = null;
    pinModalSecret.value = '';
    void fetchStaff();
}

// ---- Edit flow --------------------------------------------------

function openEdit(row: PosStaff): void {
    editTarget.value = row;
    editForm.name = row.name;
    editForm.branch_id = row.branch.id;
    editForm.position = (row.position ?? StaffPosition.Cashier) as StaffPositionValue;
    editForm.phone = row.phone ?? '';
    editForm.staff_code = row.staff_code ?? '';
    editForm.hired_at = row.hired_at ?? '';
    editFieldErrors.value = {};
    editError.value = null;
    editOpen.value = true;
}

async function submitEdit(): Promise<void> {
    if (!editTarget.value) return;
    editing.value = true;
    editFieldErrors.value = {};
    editError.value = null;
    try {
        await updatePosStaff(editTarget.value.uuid, {
            name: editForm.name,
            branch_id: editForm.branch_id,
            position: editForm.position,
            phone: editForm.phone || null,
            staff_code: editForm.staff_code || null,
            hired_at: editForm.hired_at || null,
        });
        editOpen.value = false;
        await fetchStaff();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            editFieldErrors.value = err.payload.errors;
            editError.value = t('pos_staff.validation_summary');
        } else {
            editError.value = err instanceof Error ? err.message : 'Update failed';
        }
    } finally {
        editing.value = false;
    }
}

// ---- Suspend / Reactivate ---------------------------------------

async function toggleSuspension(row: PosStaff): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        if (row.status === StaffStatus.Suspended) {
            await reactivatePosStaff(row.uuid);
        } else {
            await suspendPosStaff(row.uuid);
        }
        await fetchStaff();
    } catch (err) {
        error.value = readError(err);
    } finally {
        rowBusy.value[row.id] = false;
    }
}

// ---- Terminate (destructive — needs confirm) --------------------

function openTerminate(row: PosStaff): void {
    terminateTarget.value = row;
}

async function confirmTerminate(): Promise<void> {
    if (!terminateTarget.value) return;
    terminating.value = true;
    try {
        await terminatePosStaff(terminateTarget.value.uuid);
        terminateTarget.value = null;
        await fetchStaff();
    } catch (err) {
        error.value = readError(err);
    } finally {
        terminating.value = false;
    }
}

// ---- Reset PIN --------------------------------------------------

async function onResetPin(row: PosStaff): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        const response = await resetPosStaffPin(row.uuid);
        pinModalStaff.value = response.data;
        pinModalSecret.value = response.plaintext_pin;
        pinCopied.value = false;
        pinModalOpen.value = true;
    } catch (err) {
        error.value = readError(err);
    } finally {
        rowBusy.value[row.id] = false;
    }
}

function readError(err: unknown): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        return String((err.payload as { message?: unknown }).message ?? 'Action failed');
    }
    return err instanceof Error ? err.message : 'Action failed';
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('pos_staff.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('pos_staff.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('pos_staff.subtitle') }}
                    </p>
                </div>

                <button
                    v-if="can(MerchantPermission.PosStaffCreate)"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-teal-600/40 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="branches.length === 0"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('pos_staff.create.button') }}
                </button>
            </div>

            <!-- Branch hint -->
            <div v-if="branches.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                {{ t('pos_staff.no_branches_hint') }}
            </div>

            <!-- Search filter -->
            <input
                v-model="search"
                type="search"
                :placeholder="t('pos_staff.search_placeholder')"
                class="w-full max-w-md rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
            >

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Data table -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="filteredStaff.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Users class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('pos_staff.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.position') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.branch') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.status') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.hired') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.last_login') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in filteredStaff" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.name }}</span>
                                    <span v-if="row.staff_code" class="block text-xs font-mono text-slate-500">#{{ row.staff_code }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ positionLabel(row.position) }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ row.branch.name ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(row.status)">
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDate(row.hired_at) }}</td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ formatTimestamp(row.last_login_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            v-if="can(MerchantPermission.PosStaffUpdate)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openEdit(row)"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('pos_staff.actions.edit') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.PosStaffUpdate)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                            :disabled="rowBusy[row.id]"
                                            @click="onResetPin(row)"
                                        >
                                            <KeyRound class="size-3.5" :class="{ 'animate-spin': rowBusy[row.id] }" />
                                            {{ t('pos_staff.actions.reset_pin') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.PosStaffRevoke)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60"
                                            :class="row.status === StaffStatus.Suspended
                                                ? 'border-teal-200 text-teal-700 hover:bg-teal-50'
                                                : 'border-amber-200 text-amber-700 hover:bg-amber-50'"
                                            :disabled="rowBusy[row.id]"
                                            @click="toggleSuspension(row)"
                                        >
                                            <ShieldCheck v-if="row.status === StaffStatus.Suspended" class="size-3.5" />
                                            <ShieldOff v-else class="size-3.5" />
                                            {{ row.status === StaffStatus.Suspended ? t('pos_staff.actions.reactivate') : t('pos_staff.actions.suspend') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.PosStaffRevoke)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                            @click="openTerminate(row)"
                                        >
                                            <UserMinus class="size-3.5" />
                                            {{ t('pos_staff.actions.terminate') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ================= CREATE MODAL ================== -->
        <BaseModal v-if="createOpen" size="lg" :loading="creating" @close="createOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('pos_staff.create.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('pos_staff.create.subtitle') }}</p>
            </template>

            <form id="pos-staff-create-form" class="space-y-4" @submit.prevent="submitCreate">
                <div v-if="createError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ createError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.name') }} *</span>
                    <input v-model="createForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="createFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.name[0] }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.branch') }} *</span>
                    <select v-model.number="createForm.branch_id" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <p v-if="createFieldErrors.branch_id" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.branch_id[0] }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.position') }} *</span>
                    <select v-model="createForm.position" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in positionOptions" :key="opt.value" :value="opt.value">
                            {{ t(`pos_staff.positions.${opt.key}`) }}
                        </option>
                    </select>
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.phone') }}</span>
                        <input v-model="createForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.staff_code') }}</span>
                        <input v-model="createForm.staff_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="createFieldErrors.staff_code" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.staff_code[0] }}</p>
                    </label>
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.hired_at') }}</span>
                    <input v-model="createForm.hired_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="createOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="pos-staff-create-form" :disabled="creating" class="rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg disabled:cursor-wait disabled:opacity-60">
                        {{ creating ? t('pos_staff.create.submitting') : t('pos_staff.create.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============== ONE-SHOT PIN MODAL ============== -->
        <BaseModal v-if="pinModalOpen && pinModalStaff" size="lg" @close="closePinModal">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('pos_staff.pin_modal.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ t('pos_staff.pin_modal.subtitle', { name: pinModalStaff.name }) }}
                </p>
            </template>

            <div class="space-y-4">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                    {{ t('pos_staff.pin_modal.one_shot_warning') }}
                </div>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pos_staff.pin_modal.pin_label') }}</span>
                    <div class="mt-2 flex gap-2">
                        <input
                            id="pos-staff-pin-out"
                            :value="pinModalSecret"
                            readonly
                            class="flex-1 rounded-lg border border-slate-200 px-3 py-3 text-center text-2xl font-mono tracking-[0.5em] text-slate-950 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-semibold transition"
                            :class="pinCopied ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-700 hover:bg-slate-50'"
                            @click="copyPin"
                        >
                            <Copy class="size-4" />
                            {{ pinCopied ? t('pos_staff.pin_modal.copied') : t('pos_staff.pin_modal.copy') }}
                        </button>
                    </div>
                </label>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800" @click="closePinModal">
                        {{ t('pos_staff.pin_modal.done') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= EDIT MODAL ================== -->
        <BaseModal v-if="editOpen && editTarget" size="lg" :loading="editing" @close="editOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('pos_staff.edit.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ editTarget.name }}</p>
            </template>

            <form id="pos-staff-edit-form" class="space-y-4" @submit.prevent="submitEdit">
                <div v-if="editError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ editError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.name') }}</span>
                    <input v-model="editForm.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="editFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.name[0] }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.branch') }}</span>
                    <select v-model.number="editForm.branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <p v-if="editFieldErrors.branch_id" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.branch_id[0] }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.position') }}</span>
                    <select v-model="editForm.position" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in positionOptions" :key="opt.value" :value="opt.value">
                            {{ t(`pos_staff.positions.${opt.key}`) }}
                        </option>
                    </select>
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.phone') }}</span>
                        <input v-model="editForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.staff_code') }}</span>
                        <input v-model="editForm.staff_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="editFieldErrors.staff_code" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.staff_code[0] }}</p>
                    </label>
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('pos_staff.fields.hired_at') }}</span>
                    <input v-model="editForm.hired_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="pos-staff-edit-form" :disabled="editing" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ editing ? t('pos_staff.edit.submitting') : t('pos_staff.edit.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============ TERMINATE CONFIRM ============ -->
        <BaseModal v-if="terminateTarget" size="md" :title="t('pos_staff.terminate_dialog.title')" :loading="terminating" @close="terminateTarget = null">
            <div class="text-sm text-slate-700">
                <p>{{ t('pos_staff.terminate_dialog.body', { name: terminateTarget.name }) }}</p>
                <p class="mt-3 text-xs text-slate-500">{{ t('pos_staff.terminate_dialog.note') }}</p>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="terminateTarget = null">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="button" :disabled="terminating" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmTerminate">
                        {{ terminating ? t('pos_staff.terminate_dialog.submitting') : t('pos_staff.terminate_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

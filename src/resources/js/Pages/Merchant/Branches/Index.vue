<script setup lang="ts">
/**
 * Branches — merchant manages their own locations.
 *
 * Phase 4.7. List + edit. NO create / delete here — those are
 * pos_admin-only operations because they have CR/regulatory
 * impact and downstream device-fleet effects.
 *
 * Permission gating:
 *   - Page reachable when MerchantPermission.BranchesView
 *   - Edit button visible only when BranchesUpdate
 *   - Status select inside the modal is enabled only when
 *     BranchesTransitionStatus (SuperAdmin) — Manager sees the
 *     field disabled with a tooltip
 */

import { Building2, MonitorSmartphone, Pencil, Plus } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    listBranchDevices,
    listMerchantBranches,
    updateMerchantBranch,
    type BranchDevice,
    type BranchOrderType,
    type BranchStatus,
    type MerchantBranch,
    type OpeningDay,
    type UpdateMerchantBranchPayload,
} from '@/lib/api/branches';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

// ---- Table state ------------------------------------------------
const branches = ref<MerchantBranch[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');

// ---- Catalogues -------------------------------------------------
const orderTypeOptions: { value: BranchOrderType; key: string }[] = [
    { value: 'quick', key: 'quick' },
    { value: 'dine_in', key: 'dine_in' },
    { value: 'to_go', key: 'to_go' },
    { value: 'delivery', key: 'delivery' },
    { value: 'car', key: 'car' },
];

const statusOptions: { value: BranchStatus; key: string }[] = [
    { value: 'active', key: 'active' },
    { value: 'inactive', key: 'inactive' },
];

const weekdayKeys: readonly string[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

// ---- Filtered list ----------------------------------------------
const filteredBranches = computed<MerchantBranch[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') return branches.value;
    return branches.value.filter((b) =>
        b.name.toLowerCase().includes(term)
        || (b.name_ar ?? '').toLowerCase().includes(term)
        || (b.code ?? '').toLowerCase().includes(term)
        || (b.manager_name ?? '').toLowerCase().includes(term),
    );
});

// ---- Devices modal (read-only) ----------------------------------
const devicesOpen = ref(false);
const devicesLoading = ref(false);
const devicesError = ref<string | null>(null);
const devicesBranch = ref<MerchantBranch | null>(null);
const devicesList = ref<BranchDevice[]>([]);

// ---- Edit modal -------------------------------------------------
const editOpen = ref(false);
const editing = ref(false);
const editFieldErrors = ref<Record<string, string[]>>({});
const editError = ref<string | null>(null);
const editTarget = ref<MerchantBranch | null>(null);
const editForm = reactive<{
    name: string;
    name_ar: string;
    manager_name: string;
    phone: string;
    email: string;
    address: string;
    default_order_type: BranchOrderType;
    status: BranchStatus;
    opening_hours: Record<string, { open: string; close: string; closed: boolean }>;
}>({
    name: '',
    name_ar: '',
    manager_name: '',
    phone: '',
    email: '',
    address: '',
    default_order_type: 'quick',
    status: 'active',
    opening_hours: defaultOpeningHours(),
});

function defaultOpeningHours(): Record<string, { open: string; close: string; closed: boolean }> {
    const out: Record<string, { open: string; close: string; closed: boolean }> = {};
    for (const day of weekdayKeys) {
        out[day] = { open: '09:00', close: '22:00', closed: false };
    }
    return out;
}

// ---- Formatters -------------------------------------------------
function statusLabel(status: BranchStatus | null): string {
    if (!status) return '—';
    return t(`branches.statuses.${status}`);
}

function statusBadgeClass(status: BranchStatus | null): string {
    switch (status) {
        case 'active': return 'bg-emerald-100 text-emerald-700';
        case 'inactive': return 'bg-slate-200 text-slate-700';
        default: return 'bg-slate-100 text-slate-700';
    }
}

function orderTypeLabel(orderType: BranchOrderType | null): string {
    if (!orderType) return '—';
    return t(`branches.order_types.${orderType}`);
}

function formatDateTime(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function deviceStatusClass(status: string | null): string {
    switch (status) {
        case 'active': return 'bg-emerald-100 text-emerald-700';
        case 'assigned': return 'bg-sky-100 text-sky-700';
        case 'blocked':
        case 'inactive': return 'bg-rose-100 text-rose-700';
        default: return 'bg-slate-100 text-slate-600';
    }
}

// ---- Fetcher ----------------------------------------------------
async function fetchBranches(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listMerchantBranches();
        branches.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load branches';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void fetchBranches();
});

// ---- Devices flow (read-only) -----------------------------------

async function openDevices(row: MerchantBranch): Promise<void> {
    devicesBranch.value = row;
    devicesList.value = [];
    devicesError.value = null;
    devicesOpen.value = true;
    devicesLoading.value = true;
    try {
        const response = await listBranchDevices(row.uuid);
        devicesList.value = response.data;
    } catch (err) {
        devicesError.value = err instanceof Error ? err.message : 'Failed to load devices';
    } finally {
        devicesLoading.value = false;
    }
}

// ---- Edit flow --------------------------------------------------

function openEdit(row: MerchantBranch): void {
    editTarget.value = row;
    editForm.name = row.name;
    editForm.name_ar = row.name_ar ?? '';
    editForm.manager_name = row.manager_name ?? '';
    editForm.phone = row.phone ?? '';
    editForm.email = row.email ?? '';
    editForm.address = row.address ?? '';
    editForm.default_order_type = (row.default_order_type ?? 'quick') as BranchOrderType;
    editForm.status = (row.status ?? 'active') as BranchStatus;

    // Merge stored hours over the default skeleton so missing
    // days are pre-populated with sane values instead of blanks.
    const fresh = defaultOpeningHours();
    if (row.opening_hours_json) {
        for (const [day, hours] of Object.entries(row.opening_hours_json) as [string, OpeningDay][]) {
            fresh[day] = {
                open: hours.open ?? '09:00',
                close: hours.close ?? '22:00',
                closed: hours.closed ?? false,
            };
        }
    }
    editForm.opening_hours = fresh;

    editFieldErrors.value = {};
    editError.value = null;
    editOpen.value = true;
}

async function submitEdit(): Promise<void> {
    if (!editTarget.value) return;
    editing.value = true;
    editFieldErrors.value = {};
    editError.value = null;

    const payload: UpdateMerchantBranchPayload = {
        name: editForm.name,
        name_ar: editForm.name_ar || null,
        manager_name: editForm.manager_name || null,
        phone: editForm.phone || null,
        email: editForm.email || null,
        address: editForm.address || null,
        default_order_type: editForm.default_order_type,
        opening_hours_json: editForm.opening_hours,
    };

    // Only send status if the actor can transition it AND it
    // actually changed. The action layer enforces the permission
    // too, but skipping the field client-side avoids a 403 round-
    // trip on the common case where Manager edits a Manager-only
    // field and the form pristinely round-trips status.
    if (
        can(MerchantPermission.BranchesTransitionStatus)
        && editForm.status !== editTarget.value.status
    ) {
        payload.status = editForm.status;
    }

    try {
        const response = await updateMerchantBranch(editTarget.value.uuid, payload);
        editOpen.value = false;
        // Replace the row in-place so the table reflects the edit
        // without a full refetch.
        const idx = branches.value.findIndex((b) => b.uuid === response.data.uuid);
        if (idx >= 0) {
            branches.value[idx] = response.data;
        }
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            editFieldErrors.value = err.payload.errors;
            editError.value = t('branches.validation_summary');
        } else if (err instanceof ApiError && err.status === 403) {
            const payload = err.payload as { message?: string } | null;
            editError.value = payload?.message ?? t('branches.permission_error');
        } else {
            editError.value = err instanceof Error ? err.message : 'Update failed';
        }
    } finally {
        editing.value = false;
    }
}

const canEditStatus = computed(() => can(MerchantPermission.BranchesTransitionStatus));
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('branches.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('branches.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('branches.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Admin-only hint -->
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                <Plus class="me-1 inline size-3.5" />
                {{ t('branches.add_branch_hint') }}
            </div>

            <!-- Search -->
            <input
                v-model="search"
                type="search"
                :placeholder="t('branches.search_placeholder')"
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

                <div v-else-if="filteredBranches.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Building2 class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('branches.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.manager') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.phone') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.order_type') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in filteredBranches" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <RouterLink :to="`/branches/${row.uuid}`" class="block text-sm font-semibold text-slate-950 transition hover:text-teal-700">{{ row.name }}</RouterLink>
                                    <span v-if="row.name_ar" class="block text-xs text-slate-500">{{ row.name_ar }}</span>
                                    <span v-if="row.code" class="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-600">{{ row.code }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ row.manager_name ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm font-mono text-slate-600">{{ row.phone ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ orderTypeLabel(row.default_order_type) }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(row.status)">
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openDevices(row)"
                                        >
                                            <MonitorSmartphone class="size-3.5" />
                                            {{ t('branches.actions.devices') }}
                                        </button>
                                        <button
                                            v-if="can(MerchantPermission.BranchesUpdate)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openEdit(row)"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('branches.actions.edit') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ============== DEVICES MODAL (read-only) ============== -->
        <BaseModal v-if="devicesOpen && devicesBranch" size="2xl" @close="devicesOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('branches.devices.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ devicesBranch.name }}</p>
            </template>

            <p class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                {{ t('branches.devices.read_only_hint') }}
            </p>

            <div v-if="devicesLoading" class="p-6 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="devicesError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ devicesError }}</div>
            <div v-else-if="devicesList.length === 0" class="flex flex-col items-center gap-2 p-8 text-center text-slate-500">
                <MonitorSmartphone class="size-9 text-slate-300" />
                <p class="text-sm font-semibold">{{ t('branches.devices.empty') }}</p>
            </div>
            <ul v-else class="space-y-2">
                <li
                    v-for="device in devicesList"
                    :key="device.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ device.name || device.kiosk_id || device.serial_number || '—' }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            <span class="font-medium">{{ device.device_type ?? '—' }}</span>
                            <span v-if="device.serial_number"> &middot; {{ device.serial_number }}</span>
                            <span v-if="device.last_seen_at"> &middot; {{ t('branches.devices.last_seen') }} {{ formatDateTime(device.last_seen_at) }}</span>
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="deviceStatusClass(device.status)">{{ device.status ?? '—' }}</span>
                </li>
            </ul>
        </BaseModal>

        <!-- ================= EDIT MODAL ================== -->
        <BaseModal v-if="editOpen && editTarget" size="3xl" :loading="editing" @close="editOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('branches.edit.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    <span v-if="editTarget.code" class="font-mono text-xs">{{ editTarget.code }}</span>
                    <span v-else>{{ editTarget.name }}</span>
                </p>
            </template>

            <form id="edit-branch-form" class="space-y-5" @submit.prevent="submitEdit">
                    <div v-if="editError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ editError }}
                    </div>

                    <!-- Identity -->
                    <fieldset class="space-y-3">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.fieldset.identity') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name') }} *</span>
                                <input v-model="editForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="editFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.name[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name_ar') }}</span>
                                <input v-model="editForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                        </div>
                    </fieldset>

                    <!-- Contact -->
                    <fieldset class="space-y-3">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.fieldset.contact') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.manager_name') }}</span>
                                <input v-model="editForm.manager_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.phone') }}</span>
                                <input v-model="editForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.email') }}</span>
                                <input v-model="editForm.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="editFieldErrors.email" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.email[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.address') }}</span>
                                <input v-model="editForm.address" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                        </div>
                    </fieldset>

                    <!-- Operational -->
                    <fieldset class="space-y-3">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.fieldset.operational') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.default_order_type') }}</span>
                                <select v-model="editForm.default_order_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option v-for="opt in orderTypeOptions" :key="opt.value" :value="opt.value">{{ t(`branches.order_types.${opt.key}`) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.status') }}</span>
                                <select
                                    v-model="editForm.status"
                                    :disabled="!canEditStatus"
                                    :title="canEditStatus ? '' : t('branches.fields.status_disabled_hint')"
                                    class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
                                >
                                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ t(`branches.statuses.${opt.key}`) }}</option>
                                </select>
                                <p v-if="!canEditStatus" class="mt-1 text-xs text-slate-500">{{ t('branches.fields.status_disabled_hint') }}</p>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Opening hours -->
                    <fieldset class="space-y-3">
                        <legend class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.fieldset.hours') }}</legend>
                        <div class="space-y-2">
                            <div
                                v-for="day in weekdayKeys"
                                :key="day"
                                class="grid grid-cols-12 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2"
                            >
                                <span class="col-span-3 text-sm font-medium text-slate-700">{{ t(`branches.weekdays.${day}`) }}</span>
                                <label class="col-span-3 flex items-center gap-2 text-xs text-slate-700">
                                    <input
                                        type="checkbox"
                                        :checked="editForm.opening_hours[day].closed"
                                        class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                        @change="editForm.opening_hours[day].closed = ($event.target as HTMLInputElement).checked"
                                    >
                                    {{ t('branches.fields.closed_day') }}
                                </label>
                                <input
                                    v-model="editForm.opening_hours[day].open"
                                    type="time"
                                    :disabled="editForm.opening_hours[day].closed"
                                    class="col-span-3 rounded-lg border border-slate-200 px-2 py-1 text-sm disabled:cursor-not-allowed disabled:bg-slate-50"
                                >
                                <input
                                    v-model="editForm.opening_hours[day].close"
                                    type="time"
                                    :disabled="editForm.opening_hours[day].closed"
                                    class="col-span-3 rounded-lg border border-slate-200 px-2 py-1 text-sm disabled:cursor-not-allowed disabled:bg-slate-50"
                                >
                            </div>
                        </div>
                    </fieldset>

            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="edit-branch-form" :disabled="editing" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ editing ? t('branches.edit.submitting') : t('branches.edit.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

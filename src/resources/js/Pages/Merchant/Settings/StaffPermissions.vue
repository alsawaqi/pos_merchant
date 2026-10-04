<script setup lang="ts">
/**
 * LAUNCH-P5 B1 — Staff permissions (owner decision 1: a tick list per
 * position). It replaces Settings → Order cancellation: the four position
 * lists (order cancellation, manager approval, device reports, kitchen
 * access) are now one matrix of 5 positions × 19 actions, plus the maximum
 * manual discount % per position. Anything not ticked needs a manager's
 * approval on the till and handheld. The server keeps the four old lists in
 * sync for old app builds and audits every change.
 *
 * Permission gating:
 *   - the matrix: StaffPermissionsManage (the server 403s GET + PUT without it)
 *   - the void + comp reasons below it: OrdersCancel (unchanged)
 */

import { Pencil, Plus, RotateCcw, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { getStaffPermissions, updateStaffPermissions, type StaffPermissionsPayload } from '@/lib/api/staffPermissions';
import {
    actionKey,
    approverPositions,
    cloneMatrix,
    diffMatrix,
    isAlwaysOn,
    validLimit,
    type PositionMatrix,
} from '@/lib/staffPermissions';
import {
    createCompReason,
    createVoidReason,
    deleteCompReason,
    deleteVoidReason,
    listCompReasons,
    listVoidReasons,
    updateCompReason,
    updateVoidReason,
    type CompReason,
    type VoidReason,
} from '@/lib/api/orderReasons';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManagePermissions = computed(() => can(MerchantPermission.StaffPermissionsManage));
// The void + comp reason lists keep their orders.cancel gate.
const canManage = computed(() => can(MerchantPermission.OrdersCancel));

function apiErrorMessage(e: unknown, fallback?: string): string {
    if (e instanceof ApiError) {
        const v = e.firstValidationMessage();
        if (v) {
            return v;
        }
        const payload = e.payload as { message?: unknown } | null;
        if (payload && typeof payload.message === 'string') {
            return payload.message;
        }
    }
    return fallback ?? t('settings.staff_permissions.save_failed');
}

// =================== LAUNCH-P5 B1 — the tick list ===================

const positions = ref<string[]>([]);
const actions = ref<string[]>([]);
const alwaysOn = ref<Record<string, string[]>>({});
const saved = ref<PositionMatrix>({});
const draft = ref<PositionMatrix>({});
const defaults = ref<PositionMatrix>({});

const matrixLoading = ref(false);
const matrixLoadError = ref<string | null>(null);
const matrixSaving = ref(false);
const matrixSaveError = ref<string | null>(null);
const matrixSaveSuccess = ref(false);

const changes = computed(() => diffMatrix(saved.value, draft.value));
const dirty = computed(() => Object.keys(changes.value).length > 0);
const noApprover = computed(() => positions.value.length > 0 && approverPositions(draft.value).length === 0);
const badLimit = computed(() => positions.value.some((p) => !validLimit(draft.value[p]?.discount_max_percent)));
const canSaveMatrix = computed(
    () => canManagePermissions.value && dirty.value && !noApprover.value && !badLimit.value && !matrixSaving.value,
);

function applyPayload(data: StaffPermissionsPayload): void {
    positions.value = data.positions;
    actions.value = data.actions;
    alwaysOn.value = data.always_on;
    saved.value = cloneMatrix(data.permissions);
    draft.value = cloneMatrix(data.permissions);
    defaults.value = cloneMatrix(data.defaults);
}

async function fetchMatrix(): Promise<void> {
    if (!canManagePermissions.value) {
        return;
    }
    matrixLoading.value = true;
    matrixLoadError.value = null;
    try {
        const res = await getStaffPermissions();
        applyPayload(res.data);
    } catch (e) {
        matrixLoadError.value = apiErrorMessage(e);
    } finally {
        matrixLoading.value = false;
    }
}

onMounted(() => { void fetchMatrix(); });

function locked(position: string, action: string): boolean {
    return isAlwaysOn(alwaysOn.value, position, action);
}

function toggleCell(position: string, action: string): void {
    if (!canManagePermissions.value || locked(position, action)) {
        return;
    }
    matrixSaveSuccess.value = false;
    matrixSaveError.value = null;
    const row = draft.value[position];
    if (row) {
        row.actions[action] = !row.actions[action];
    }
}

function limitValue(position: string): number | string {
    const value = draft.value[position]?.discount_max_percent;
    return value === undefined || Number.isNaN(value) ? '' : value;
}

function setLimit(position: string, raw: string): void {
    matrixSaveSuccess.value = false;
    matrixSaveError.value = null;
    const row = draft.value[position];
    if (row) {
        row.discount_max_percent = raw.trim() === '' ? Number.NaN : Number(raw);
    }
}

function restoreDefaults(): void {
    matrixSaveSuccess.value = false;
    matrixSaveError.value = null;
    draft.value = cloneMatrix(defaults.value);
}

function discardChanges(): void {
    matrixSaveError.value = null;
    draft.value = cloneMatrix(saved.value);
}

async function saveMatrix(): Promise<void> {
    if (!canSaveMatrix.value) {
        return;
    }
    matrixSaving.value = true;
    matrixSaveError.value = null;
    matrixSaveSuccess.value = false;
    try {
        const res = await updateStaffPermissions(changes.value);
        applyPayload(res.data);
        matrixSaveSuccess.value = true;
    } catch (e) {
        matrixSaveError.value = apiErrorMessage(e);
    } finally {
        matrixSaving.value = false;
    }
}

// =================== Phase B — void + comp reason lists ===================
// Two CRUD tables sharing one modal pattern (kind discriminates).
// The index endpoints lazily seed the Additions doc's defaults.

const voidReasons = ref<VoidReason[]>([]);
const compReasons = ref<CompReason[]>([]);
const reasonsError = ref<string | null>(null);

async function fetchReasons(): Promise<void> {
    if (!canManage.value) {
        return;
    }
    try {
        const [v, c] = await Promise.all([listVoidReasons(), listCompReasons()]);
        voidReasons.value = v.data;
        compReasons.value = c.data;
    } catch (e) {
        reasonsError.value = apiErrorMessage(e);
    }
}

onMounted(() => { void fetchReasons(); });

type ReasonKind = 'void' | 'comp';
const reasonModalOpen = ref(false);
const reasonModalBusy = ref(false);
const reasonModalError = ref<string | null>(null);
const reasonModalKind = ref<ReasonKind>('void');
const reasonModalTarget = ref<VoidReason | CompReason | null>(null);
const reasonForm = reactive<{
    name: string;
    name_ar: string;
    affects_inventory: boolean;
    requires_manager: boolean;
    max_amount: string;
    is_active: boolean;
}>({ name: '', name_ar: '', affects_inventory: false, requires_manager: true, max_amount: '', is_active: true });

function openCreateReason(kind: ReasonKind): void {
    reasonModalKind.value = kind;
    reasonModalTarget.value = null;
    reasonForm.name = '';
    reasonForm.name_ar = '';
    reasonForm.affects_inventory = false;
    reasonForm.requires_manager = true;
    reasonForm.max_amount = '';
    reasonForm.is_active = true;
    reasonModalError.value = null;
    reasonModalOpen.value = true;
}

function openEditReason(kind: ReasonKind, reason: VoidReason | CompReason): void {
    reasonModalKind.value = kind;
    reasonModalTarget.value = reason;
    reasonForm.name = reason.name;
    reasonForm.name_ar = reason.name_ar ?? '';
    reasonForm.affects_inventory = kind === 'void' ? (reason as VoidReason).affects_inventory : false;
    reasonForm.requires_manager = kind === 'void' ? (reason as VoidReason).requires_manager : true;
    reasonForm.max_amount = kind === 'comp' ? ((reason as CompReason).max_amount ?? '') : '';
    reasonForm.is_active = reason.is_active;
    reasonModalError.value = null;
    reasonModalOpen.value = true;
}

async function saveReason(): Promise<void> {
    reasonModalBusy.value = true;
    reasonModalError.value = null;
    try {
        if (reasonModalKind.value === 'void') {
            const payload = {
                name: reasonForm.name.trim(),
                name_ar: reasonForm.name_ar.trim() || null,
                affects_inventory: reasonForm.affects_inventory,
                requires_manager: reasonForm.requires_manager,
                is_active: reasonForm.is_active,
            };
            if (reasonModalTarget.value) {
                await updateVoidReason(reasonModalTarget.value.uuid, payload);
            } else {
                await createVoidReason(payload);
            }
        } else {
            const payload = {
                name: reasonForm.name.trim(),
                name_ar: reasonForm.name_ar.trim() || null,
                max_amount: String(reasonForm.max_amount).trim() === '' ? null : reasonForm.max_amount,
                is_active: reasonForm.is_active,
            };
            if (reasonModalTarget.value) {
                await updateCompReason(reasonModalTarget.value.uuid, payload);
            } else {
                await createCompReason(payload);
            }
        }
        reasonModalOpen.value = false;
        await fetchReasons();
    } catch (e) {
        reasonModalError.value = apiErrorMessage(e);
    } finally {
        reasonModalBusy.value = false;
    }
}

const reasonDeleteTarget = ref<{ kind: ReasonKind; reason: VoidReason | CompReason } | null>(null);
const reasonDeleteBusy = ref(false);

async function confirmDeleteReason(): Promise<void> {
    if (!reasonDeleteTarget.value) {
        return;
    }
    reasonDeleteBusy.value = true;
    try {
        if (reasonDeleteTarget.value.kind === 'void') {
            await deleteVoidReason(reasonDeleteTarget.value.reason.uuid);
        } else {
            await deleteCompReason(reasonDeleteTarget.value.reason.uuid);
        }
        reasonDeleteTarget.value = null;
        await fetchReasons();
    } catch (e) {
        reasonsError.value = apiErrorMessage(e);
    } finally {
        reasonDeleteBusy.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.staff_permissions.title') }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ t('settings.staff_permissions.subtitle') }}</p>
            </div>

            <!-- ============ LAUNCH-P5 B1 — STAFF PERMISSIONS (tick list) ============ -->
            <div v-if="!canManagePermissions" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700" data-test="staff-permissions-forbidden">
                {{ t('settings.staff_permissions.forbidden') }}
            </div>

            <template v-else>
                <div v-if="matrixLoadError" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ matrixLoadError }}
                </div>

                <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div v-if="matrixLoading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                    <div v-else-if="positions.length > 0" class="overflow-x-auto">
                        <table class="w-full text-sm" data-test="staff-permissions-matrix">
                            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ t('settings.staff_permissions.action_column') }}</th>
                                    <th v-for="position in positions" :key="position" class="px-3 py-3 text-center font-semibold">
                                        {{ t(`pos_staff.positions.${position}`) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template v-for="action in actions" :key="action">
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5">
                                            <span class="block font-medium text-slate-900">{{ t(`settings.staff_permissions.actions.${actionKey(action)}.label`) }}</span>
                                            <span class="block text-xs text-slate-500">{{ t(`settings.staff_permissions.actions.${actionKey(action)}.help`) }}</span>
                                        </td>
                                        <td v-for="position in positions" :key="position" class="px-3 py-2.5 text-center">
                                            <input
                                                type="checkbox"
                                                :data-test="`cell-${position}-${action}`"
                                                :checked="draft[position]?.actions[action] === true"
                                                :disabled="locked(position, action)"
                                                :title="locked(position, action) ? t('settings.staff_permissions.always_on') : undefined"
                                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 disabled:opacity-60"
                                                @change="toggleCell(position, action)"
                                            >
                                        </td>
                                    </tr>
                                    <tr v-if="action === 'discount.manual'" class="bg-slate-50/40">
                                        <td class="px-4 py-2.5">
                                            <span class="block font-medium text-slate-900">{{ t('settings.staff_permissions.discount_limit_label') }}</span>
                                            <span class="block text-xs text-slate-500">{{ t('settings.staff_permissions.discount_limit_help') }}</span>
                                        </td>
                                        <td v-for="position in positions" :key="position" class="px-3 py-2.5 text-center">
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="1"
                                                :data-test="`limit-${position}`"
                                                :value="limitValue(position)"
                                                class="w-20 rounded-lg border px-2 py-1.5 text-center text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                                :class="validLimit(draft[position]?.discount_max_percent) ? 'border-slate-200' : 'border-rose-400'"
                                                @input="setLimit(position, ($event.target as HTMLInputElement).value)"
                                            >
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="positions.length > 0" class="space-y-3 border-t border-slate-200 p-4 sm:p-6">
                        <p class="text-xs text-slate-500">{{ t('settings.staff_permissions.legend') }}</p>
                        <p v-if="noApprover" class="text-sm text-rose-600" data-test="no-approver">{{ t('settings.staff_permissions.no_approver') }}</p>
                        <p v-if="badLimit" class="text-sm text-rose-600">{{ t('settings.staff_permissions.invalid_limit') }}</p>
                        <p v-if="matrixSaveError" class="text-sm text-rose-600">{{ matrixSaveError }}</p>
                        <p v-if="matrixSaveSuccess" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            {{ t('settings.staff_permissions.save_success') }}
                        </p>
                        <div class="flex flex-wrap items-center justify-end gap-3">
                            <span v-if="dirty" class="me-auto text-xs font-semibold text-amber-700">{{ t('settings.staff_permissions.unsaved') }}</span>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                data-test="restore-defaults"
                                @click="restoreDefaults"
                            >
                                <RotateCcw class="size-4" />
                                {{ t('settings.staff_permissions.restore_defaults') }}
                            </button>
                            <button
                                v-if="dirty"
                                type="button"
                                class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                @click="discardChanges"
                            >
                                {{ t('settings.staff_permissions.discard') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
                                data-test="save-permissions"
                                :disabled="!canSaveMatrix"
                                @click="saveMatrix"
                            >
                                <ShieldCheck class="size-4" />
                                {{ matrixSaving ? t('common.saving') : t('settings.staff_permissions.save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- =============== Phase B — VOID + COMP REASONS =============== -->
            <div v-if="canManage" class="mt-8 space-y-8">
                <div v-if="reasonsError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ reasonsError }}
                </div>

                <section v-for="kind in (['void', 'comp'] as const)" :key="kind" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">
                                {{ kind === 'void' ? t('settings.reasons.void_title') : t('settings.reasons.comp_title') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ kind === 'void' ? t('settings.reasons.void_subtitle') : t('settings.reasons.comp_subtitle') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                            @click="openCreateReason(kind)"
                        >
                            <Plus class="size-3.5" />
                            {{ t('settings.reasons.add') }}
                        </button>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2.5 text-start font-semibold">{{ t('settings.reasons.name') }}</th>
                                <th v-if="kind === 'void'" class="px-5 py-2.5 text-center font-semibold">{{ t('settings.reasons.affects_inventory') }}</th>
                                <th v-if="kind === 'void'" class="px-5 py-2.5 text-center font-semibold">{{ t('settings.reasons.requires_manager') }}</th>
                                <th v-if="kind === 'comp'" class="px-5 py-2.5 text-end font-semibold">{{ t('settings.reasons.max_amount') }}</th>
                                <th class="px-5 py-2.5 text-center font-semibold">{{ t('settings.reasons.status') }}</th>
                                <th class="px-5 py-2.5 text-end font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="reason in (kind === 'void' ? voidReasons : compReasons)" :key="reason.uuid" class="hover:bg-slate-50/60">
                                <td class="px-5 py-2.5">
                                    <span class="font-medium text-slate-900">{{ reason.name }}</span>
                                    <span v-if="reason.name_ar" class="ms-2 text-xs text-slate-400">{{ reason.name_ar }}</span>
                                </td>
                                <td v-if="kind === 'void'" class="px-5 py-2.5 text-center">
                                    <span v-if="(reason as VoidReason).affects_inventory" class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700">{{ t('settings.reasons.food_made') }}</span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                                <td v-if="kind === 'void'" class="px-5 py-2.5 text-center">
                                    <span v-if="(reason as VoidReason).requires_manager" class="text-xs font-semibold text-slate-600">✓</span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                                <td v-if="kind === 'comp'" class="px-5 py-2.5 text-end tabular-nums text-slate-700">
                                    {{ (reason as CompReason).max_amount ?? t('settings.reasons.no_cap') }}
                                </td>
                                <td class="px-5 py-2.5 text-center">
                                    <span :class="reason.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase">
                                        {{ reason.is_active ? t('settings.reasons.active') : t('settings.reasons.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-2.5">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="grid size-7 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50" @click="openEditReason(kind, reason)">
                                            <Pencil class="size-3.5" />
                                        </button>
                                        <button type="button" class="grid size-7 place-items-center rounded-lg border border-slate-200 text-rose-600 transition hover:bg-rose-50" @click="reasonDeleteTarget = { kind, reason }">
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>

        <!-- Phase B — reason create/edit modal (kind-discriminated). -->
        <BaseModal
            v-if="reasonModalOpen"
            :title="reasonModalTarget
                ? t('settings.reasons.edit_title')
                : (reasonModalKind === 'void' ? t('settings.reasons.add_void_title') : t('settings.reasons.add_comp_title'))"
            size="md"
            :loading="reasonModalBusy"
            @close="reasonModalOpen = false"
        >
            <div class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('settings.reasons.name') }} *</span>
                    <input v-model="reasonForm.name" type="text" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('settings.reasons.name_ar') }}</span>
                    <input v-model="reasonForm.name_ar" type="text" maxlength="64" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <template v-if="reasonModalKind === 'void'">
                    <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                        <input v-model="reasonForm.affects_inventory" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                        <span>
                            <span class="block text-sm font-medium text-slate-700">{{ t('settings.reasons.affects_inventory') }}</span>
                            <span class="block text-xs text-slate-500">{{ t('settings.reasons.affects_inventory_hint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="reasonForm.requires_manager" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                        <span class="text-sm font-medium text-slate-700">{{ t('settings.reasons.requires_manager') }}</span>
                    </label>
                </template>
                <label v-else class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('settings.reasons.max_amount') }} (OMR)</span>
                    <input v-model="reasonForm.max_amount" type="number" step="0.001" min="0" :placeholder="t('settings.reasons.no_cap')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p class="mt-1 text-xs text-slate-500">{{ t('settings.reasons.max_amount_hint') }}</p>
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="reasonForm.is_active" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                    <span class="text-sm font-medium text-slate-700">{{ t('settings.reasons.active') }}</span>
                </label>
                <p v-if="reasonModalError" class="text-sm text-rose-600">{{ reasonModalError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" :disabled="reasonModalBusy" @click="reasonModalOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="button" class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60" :disabled="reasonModalBusy" @click="saveReason">
                        {{ t('common.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- Phase B — reason delete confirm. -->
        <BaseModal
            v-if="reasonDeleteTarget"
            :title="t('settings.reasons.delete_title')"
            size="sm"
            :loading="reasonDeleteBusy"
            @close="reasonDeleteTarget = null"
        >
            <p class="text-sm text-slate-600">{{ t('settings.reasons.delete_confirm', { name: reasonDeleteTarget.reason.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" :disabled="reasonDeleteBusy" @click="reasonDeleteTarget = null">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="button" class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60" :disabled="reasonDeleteBusy" @click="confirmDeleteReason">
                        {{ t('settings.reasons.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

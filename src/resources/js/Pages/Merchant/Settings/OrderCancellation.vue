<script setup lang="ts">
/**
 * Order Cancellation policy — company-level setting (v2 #14).
 *
 * The merchant chooses which staff positions may cancel an order at
 * the POS. The Main POS reads the chosen set to gate the cancel
 * action at the terminal.
 *
 * Permission gating:
 *   - Page reachable + Save only when OrdersCancel. Without it the
 *     server returns 403 on both GET and PUT; the SPA also hides the
 *     form and the sidebar entry.
 */

import { Ban, Pencil, Plus, ShieldX, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    getOrderCancellationSetting,
    updateOrderCancellationPositions,
} from '@/lib/api/orderCancellation';
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
const canManage = computed(() => can(MerchantPermission.OrdersCancel));

const available = ref<string[]>([]);
const selected = ref<string[]>([]);

const loading = ref(true);
const loadError = ref<string | null>(null);

const saving = ref(false);
const saveError = ref<string | null>(null);
const saveSuccess = ref(false);

const canSave = computed(() => canManage.value && !saving.value && selected.value.length > 0);

function apiErrorMessage(e: unknown): string {
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
    return t('settings.order_cancellation.save_failed');
}

function isChecked(position: string): boolean {
    return selected.value.includes(position);
}

function toggle(position: string): void {
    // Toggling re-arms the form: clear any stale success/error state.
    saveSuccess.value = false;
    saveError.value = null;
    if (isChecked(position)) {
        selected.value = selected.value.filter((p) => p !== position);
    } else {
        selected.value = [...selected.value, position];
    }
}

async function fetchSetting(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await getOrderCancellationSetting();
        available.value = res.data.available_positions;
        selected.value = res.data.positions;
    } catch (e) {
        loadError.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(() => { void fetchSetting(); });

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    saveError.value = null;
    saveSuccess.value = false;
    try {
        const res = await updateOrderCancellationPositions(selected.value);
        available.value = res.data.available_positions;
        selected.value = res.data.positions;
        saveSuccess.value = true;
    } catch (e) {
        saveError.value = apiErrorMessage(e);
    } finally {
        saving.value = false;
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
        <div class="mx-auto max-w-2xl">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.order_cancellation.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('settings.order_cancellation.subtitle') }}</p>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div v-if="!canManage && !loadError" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                {{ t('settings.order_cancellation.forbidden') }}
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="available.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <ShieldX class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('settings.order_cancellation.empty_state') }}</p>
                </div>
                <div v-else class="p-4 sm:p-6">
                    <p class="text-sm font-medium text-slate-700">{{ t('settings.order_cancellation.positions_label') }}</p>
                    <div class="mt-4 space-y-2">
                        <label
                            v-for="position in available"
                            :key="position"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-3 transition"
                            :class="canManage ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-60'"
                        >
                            <input
                                type="checkbox"
                                :checked="isChecked(position)"
                                :disabled="!canManage"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                @change="toggle(position)"
                            >
                            <span class="text-sm font-medium text-slate-700">{{ t(`pos_staff.positions.${position}`) }}</span>
                        </label>
                    </div>

                    <p v-if="canManage && selected.length === 0" class="mt-4 text-sm text-rose-600">
                        {{ t('settings.order_cancellation.at_least_one') }}
                    </p>
                    <p v-if="saveError" class="mt-4 text-sm text-rose-600">{{ saveError }}</p>
                    <p v-if="saveSuccess" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        {{ t('settings.order_cancellation.save_success') }}
                    </p>

                    <div v-if="canManage" class="mt-6 flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
                            :disabled="!canSave"
                            @click="save"
                        >
                            <Ban class="size-4" />
                            {{ saving ? t('common.saving') : t('settings.order_cancellation.save') }}
                        </button>
                    </div>
                </div>
            </div>

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

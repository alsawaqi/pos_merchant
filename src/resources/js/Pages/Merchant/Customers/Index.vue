<script setup lang="ts">
/**
 * Customers — Phase 6a.
 *
 * Manage the customer book + their vehicle plates. Server-side
 * search across name + phone + plate (canonical/uppercase
 * comparison) so the cashier can find a customer by any of
 * the three.
 *
 * Permission gating:
 *   - Page reachable when MerchantPermission.CustomersView
 *   - Add / Edit / Delete / plate-attach / plate-detach all
 *     visible only when CustomersManage
 *
 * Plates UX: a single inline section in the Edit modal. The
 * create flow optionally accepts initial plates via the
 * `plates: string[]` field on the create payload, attached
 * server-side in one transaction.
 */

import { Car, Coins, Gift, Pencil, Plus, Settings, Trash2, Users, Wallet } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    attachVehiclePlate,
    createCustomer,
    deleteCustomer,
    detachVehiclePlate,
    listCustomers,
    updateCustomer,
    type Customer,
    type CustomerVehiclePlate,
} from '@/lib/api/customers';
import {
    adjustPoints,
    adjustWallet,
    getCustomerLoyalty,
    getLoyaltyConfig,
    topUpWallet,
    upsertLoyaltyConfig,
    type CustomerLoyaltySummary,
    type LoyaltyConfig,
} from '@/lib/api/loyalty';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

// ---- List state ------------------------------------------------
const customers = ref<Customer[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');
let searchDebounce: ReturnType<typeof setTimeout> | null = null;
const total = ref(0);

async function fetchCustomers(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listCustomers({
            search: search.value.trim() || undefined,
        });
        customers.value = response.data;
        total.value = response.total;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load customers';
    } finally {
        loading.value = false;
    }
}

// Debounce the server-side search so we don't flood the API on
// every keystroke. 250ms is enough to type a digit-pair before
// the round-trip fires.
watch(search, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { void fetchCustomers(); }, 250);
});

onMounted(() => { void fetchCustomers(); });

// ---- Modal state -----------------------------------------------
type ModalMode = 'create' | 'edit';
const modalOpen = ref(false);
const modalMode = ref<ModalMode>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalFieldErrors = ref<Record<string, string[]>>({});
const modalTarget = ref<Customer | null>(null);
const modalForm = reactive<{ name: string; phone: string }>({ name: '', phone: '' });
// Pending initial plates for the CREATE flow (not yet sent).
const pendingPlates = ref<string[]>([]);
const newPlateInput = ref('');
// For edit mode: the live plates attached to the customer.
const editPlates = ref<CustomerVehiclePlate[]>([]);

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    modalForm.name = '';
    modalForm.phone = '';
    pendingPlates.value = [];
    newPlateInput.value = '';
    editPlates.value = [];
    modalError.value = null;
    modalFieldErrors.value = {};
    modalOpen.value = true;
}

function openEdit(customer: Customer): void {
    modalMode.value = 'edit';
    modalTarget.value = customer;
    modalForm.name = customer.name;
    modalForm.phone = customer.phone;
    pendingPlates.value = [];
    newPlateInput.value = '';
    editPlates.value = [...(customer.vehicle_plates ?? [])];
    modalError.value = null;
    modalFieldErrors.value = {};
    modalOpen.value = true;
}

function addPendingPlate(): void {
    const raw = newPlateInput.value.trim();
    if (raw === '') return;
    // Normalise to upper-case before storing in pendingPlates so
    // the local "duplicate" check matches what the server will
    // see after its own normalisation.
    const canonical = raw.replace(/\s+/g, ' ').toUpperCase();
    if (pendingPlates.value.includes(canonical)) {
        modalError.value = t('customers.errors.duplicate_plate_local');
        return;
    }
    pendingPlates.value.push(canonical);
    newPlateInput.value = '';
    modalError.value = null;
}

function removePendingPlate(plate: string): void {
    pendingPlates.value = pendingPlates.value.filter((p) => p !== plate);
}

async function attachPlateLive(): Promise<void> {
    if (!modalTarget.value) return;
    const raw = newPlateInput.value.trim();
    if (raw === '') return;
    modalBusy.value = true;
    modalError.value = null;
    try {
        const response = await attachVehiclePlate(modalTarget.value.uuid, {
            plate_number: raw,
        });
        editPlates.value.push(response.data);
        newPlateInput.value = '';
        // Reflect on the row in the underlying list so the
        // table's plate count badge stays in sync.
        const row = customers.value.find((c) => c.uuid === modalTarget.value!.uuid);
        if (row) {
            row.vehicle_plates = [...(row.vehicle_plates ?? []), response.data];
            row.vehicle_plates_count = (row.vehicle_plates_count ?? 0) + 1;
        }
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            modalError.value = payload?.message ?? t('customers.errors.attach_failed');
        } else {
            modalError.value = t('customers.errors.attach_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

async function detachPlateLive(plate: CustomerVehiclePlate): Promise<void> {
    if (!modalTarget.value) return;
    modalBusy.value = true;
    modalError.value = null;
    try {
        await detachVehiclePlate(plate.uuid);
        editPlates.value = editPlates.value.filter((p) => p.uuid !== plate.uuid);
        const row = customers.value.find((c) => c.uuid === modalTarget.value!.uuid);
        if (row) {
            row.vehicle_plates = (row.vehicle_plates ?? []).filter((p) => p.uuid !== plate.uuid);
            row.vehicle_plates_count = Math.max((row.vehicle_plates_count ?? 1) - 1, 0);
        }
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            modalError.value = payload?.message ?? t('customers.errors.detach_failed');
        } else {
            modalError.value = t('customers.errors.detach_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

async function submitModal(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    modalFieldErrors.value = {};
    try {
        if (modalMode.value === 'create') {
            const response = await createCustomer({
                name: modalForm.name,
                phone: modalForm.phone,
                plates: pendingPlates.value.length > 0 ? pendingPlates.value : undefined,
            });
            // Prepend the new row to the list so the user sees
            // it without a full refetch.
            customers.value = [response.data, ...customers.value];
            total.value += 1;
            modalOpen.value = false;
        } else if (modalTarget.value) {
            const response = await updateCustomer(modalTarget.value.uuid, {
                name: modalForm.name,
                phone: modalForm.phone,
            });
            const idx = customers.value.findIndex((c) => c.uuid === response.data.uuid);
            if (idx >= 0) {
                // Preserve the live plates list we've been editing
                // so the optimistic detach/attach updates aren't
                // overwritten by the (already-stale) response body.
                customers.value[idx] = {
                    ...response.data,
                    vehicle_plates: editPlates.value,
                    vehicle_plates_count: editPlates.value.length,
                };
            }
            modalOpen.value = false;
        }
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            modalFieldErrors.value = err.payload.errors;
            modalError.value = t('customers.errors.validation_summary');
        } else if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            modalError.value = payload?.message ?? t('customers.errors.save_failed');
        } else {
            modalError.value = t('customers.errors.save_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

// ---- Delete confirm --------------------------------------------
const confirmDelete = ref<Customer | null>(null);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);

function openDelete(customer: Customer): void {
    confirmDelete.value = customer;
    deleteError.value = null;
}

async function performDelete(): Promise<void> {
    if (!confirmDelete.value) return;
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        await deleteCustomer(confirmDelete.value.uuid);
        customers.value = customers.value.filter((c) => c.uuid !== confirmDelete.value!.uuid);
        total.value = Math.max(total.value - 1, 0);
        confirmDelete.value = null;
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            deleteError.value = payload?.message ?? t('customers.errors.delete_failed');
        } else {
            deleteError.value = t('customers.errors.delete_failed');
        }
    } finally {
        deleteBusy.value = false;
    }
}

const canManage = computed(() => can(MerchantPermission.CustomersManage));

// ---- Phase 6b — Loyalty -----------------------------------------
const canLoyaltyView = computed(() => can(MerchantPermission.LoyaltyView));
const canLoyaltyManage = computed(() => can(MerchantPermission.LoyaltyManage));

// ---- Loyalty config modal --------------------------------------
const configModalOpen = ref(false);
const configBusy = ref(false);
const configError = ref<string | null>(null);
const configForm = reactive<{ points_per_omr: number; baisas_per_point: number; is_active: boolean }>({
    points_per_omr: 0,
    baisas_per_point: 10,
    is_active: false,
});
const configLoaded = ref(false);

async function openLoyaltyConfig(): Promise<void> {
    configError.value = null;
    configModalOpen.value = true;
    if (!configLoaded.value) {
        try {
            const response = await getLoyaltyConfig();
            configForm.points_per_omr = response.data.points_per_omr;
            configForm.baisas_per_point = response.data.baisas_per_point;
            configForm.is_active = response.data.is_active;
            configLoaded.value = true;
        } catch (err) {
            configError.value = err instanceof Error ? err.message : t('loyalty.errors.config_load_failed');
        }
    }
}

async function submitLoyaltyConfig(): Promise<void> {
    configBusy.value = true;
    configError.value = null;
    try {
        await upsertLoyaltyConfig({
            points_per_omr: configForm.points_per_omr,
            baisas_per_point: configForm.baisas_per_point,
            is_active: configForm.is_active,
        });
        configModalOpen.value = false;
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            configError.value = payload?.message ?? t('loyalty.errors.config_save_failed');
        } else {
            configError.value = t('loyalty.errors.config_save_failed');
        }
    } finally {
        configBusy.value = false;
    }
}

// ---- Customer loyalty modal -------------------------------------
const loyaltyModalOpen = ref(false);
const loyaltyTarget = ref<Customer | null>(null);
const loyaltyBusy = ref(false);
const loyaltyError = ref<string | null>(null);
const loyaltySummary = ref<CustomerLoyaltySummary | null>(null);

// Point adjust form. Sign is encoded by the +/- prefix in the
// signed input; the API expects signed integer.
const pointForm = reactive<{ delta: number; reason: string }>({ delta: 0, reason: '' });
// Wallet form covers BOTH topup and adjust; the action button
// at submit time chooses which endpoint to call.
const walletForm = reactive<{ amount: string; reason: string }>({ amount: '0.000', reason: '' });

async function openLoyaltyForCustomer(customer: Customer): Promise<void> {
    loyaltyTarget.value = customer;
    loyaltyBusy.value = false;
    loyaltyError.value = null;
    loyaltySummary.value = null;
    pointForm.delta = 0;
    pointForm.reason = '';
    walletForm.amount = '0.000';
    walletForm.reason = '';
    loyaltyModalOpen.value = true;

    try {
        const response = await getCustomerLoyalty(customer.uuid);
        loyaltySummary.value = response.data;
    } catch (err) {
        loyaltyError.value = err instanceof Error ? err.message : t('loyalty.errors.summary_failed');
    }
}

async function submitPointAdjust(): Promise<void> {
    if (!loyaltyTarget.value || pointForm.delta === 0) return;
    loyaltyBusy.value = true;
    loyaltyError.value = null;
    try {
        const response = await adjustPoints(loyaltyTarget.value.uuid, {
            points_delta: pointForm.delta,
            reason: pointForm.reason,
        });
        // Refresh the summary in-place from the new balance +
        // entry. Cheaper than re-fetching the whole summary.
        if (loyaltySummary.value) {
            loyaltySummary.value.customer.points_balance = response.data.points_balance;
            loyaltySummary.value.recent_points = [
                response.data.entry,
                ...loyaltySummary.value.recent_points,
            ].slice(0, 5);
        }
        pointForm.delta = 0;
        pointForm.reason = '';
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            loyaltyError.value = payload?.message ?? t('loyalty.errors.point_adjust_failed');
        } else {
            loyaltyError.value = t('loyalty.errors.point_adjust_failed');
        }
    } finally {
        loyaltyBusy.value = false;
    }
}

async function submitWalletTopup(): Promise<void> {
    if (!loyaltyTarget.value || parseFloat(walletForm.amount) <= 0) return;
    loyaltyBusy.value = true;
    loyaltyError.value = null;
    try {
        const response = await topUpWallet(loyaltyTarget.value.uuid, {
            amount: walletForm.amount,
            reason: walletForm.reason || undefined,
        });
        if (loyaltySummary.value) {
            loyaltySummary.value.customer.wallet_balance = response.data.wallet_balance;
            loyaltySummary.value.recent_wallet = [
                response.data.entry,
                ...loyaltySummary.value.recent_wallet,
            ].slice(0, 5);
        }
        walletForm.amount = '0.000';
        walletForm.reason = '';
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            loyaltyError.value = payload?.message ?? t('loyalty.errors.wallet_topup_failed');
        } else {
            loyaltyError.value = t('loyalty.errors.wallet_topup_failed');
        }
    } finally {
        loyaltyBusy.value = false;
    }
}

async function submitWalletAdjust(): Promise<void> {
    if (!loyaltyTarget.value || parseFloat(walletForm.amount) === 0 || !walletForm.reason) return;
    loyaltyBusy.value = true;
    loyaltyError.value = null;
    try {
        const response = await adjustWallet(loyaltyTarget.value.uuid, {
            amount_delta: walletForm.amount,
            reason: walletForm.reason,
        });
        if (loyaltySummary.value) {
            loyaltySummary.value.customer.wallet_balance = response.data.wallet_balance;
            loyaltySummary.value.recent_wallet = [
                response.data.entry,
                ...loyaltySummary.value.recent_wallet,
            ].slice(0, 5);
        }
        walletForm.amount = '0.000';
        walletForm.reason = '';
    } catch (err) {
        if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            loyaltyError.value = payload?.message ?? t('loyalty.errors.wallet_adjust_failed');
        } else {
            loyaltyError.value = t('loyalty.errors.wallet_adjust_failed');
        }
    } finally {
        loyaltyBusy.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('customers.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('customers.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('customers.subtitle') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="canLoyaltyManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                        @click="openLoyaltyConfig"
                    >
                        <Settings class="size-4" />
                        {{ t('loyalty.actions.settings') }}
                    </button>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                        @click="openCreate"
                    >
                        <Plus class="size-4" />
                        {{ t('customers.actions.add') }}
                    </button>
                </div>
            </div>

            <!-- Search -->
            <input
                v-model="search"
                type="search"
                :placeholder="t('customers.search_placeholder')"
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

                <div v-else-if="customers.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Users class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('customers.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.table.phone') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.table.plates') }}</th>
                                <th v-if="canLoyaltyView" class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.table.balances') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in customers" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.name }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-mono text-slate-600">{{ row.phone }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="p in (row.vehicle_plates ?? [])"
                                            :key="p.id"
                                            class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-xs font-mono font-semibold text-slate-700"
                                        >
                                            <Car class="size-3" />
                                            {{ p.plate_number }}
                                        </span>
                                        <span v-if="(row.vehicle_plates ?? []).length === 0" class="text-xs text-slate-400">
                                            —
                                        </span>
                                    </div>
                                </td>
                                <td v-if="canLoyaltyView" class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">
                                            <Coins class="size-3" />
                                            {{ row.points_balance ?? 0 }}
                                        </span>
                                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-mono font-semibold text-emerald-700">
                                            <Wallet class="size-3" />
                                            {{ row.wallet_balance ?? '0.000' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            v-if="canLoyaltyView"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-50"
                                            @click="openLoyaltyForCustomer(row)"
                                        >
                                            <Gift class="size-3.5" />
                                            {{ t('loyalty.actions.open') }}
                                        </button>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openEdit(row)"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('customers.actions.edit') }}
                                        </button>
                                        <button
                                            v-if="canManage"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
                                            @click="openDelete(row)"
                                        >
                                            <Trash2 class="size-3.5" />
                                            {{ t('customers.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ================= CREATE / EDIT MODAL ================== -->
        <div v-if="modalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ modalMode === 'create' ? t('customers.modal.create_title') : t('customers.modal.edit_title') }}
                    </h2>
                </div>

                <form class="space-y-5 p-6" @submit.prevent="submitModal">
                    <div v-if="modalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ modalError }}
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('customers.fields.name') }}</label>
                        <input
                            v-model="modalForm.name"
                            type="text"
                            required
                            class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        >
                        <p v-if="modalFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ modalFieldErrors.name[0] }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('customers.fields.phone') }}</label>
                        <input
                            v-model="modalForm.phone"
                            type="tel"
                            required
                            :placeholder="t('customers.fields.phone_placeholder')"
                            class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        >
                        <p v-if="modalFieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ modalFieldErrors.phone[0] }}</p>
                    </div>

                    <!-- Plates section -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-slate-700">{{ t('customers.modal.plates_title') }}</h3>
                            <span class="text-xs text-slate-500">{{ t('customers.modal.plates_hint') }}</span>
                        </div>

                        <!-- Existing plates (edit mode) -->
                        <div v-if="modalMode === 'edit'" class="space-y-2">
                            <div v-for="plate in editPlates" :key="plate.id" class="flex items-center justify-between rounded-md bg-white px-3 py-2 border border-slate-200">
                                <span class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700">
                                    <Car class="size-4 text-slate-400" />
                                    {{ plate.plate_number }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="modalBusy"
                                    class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-50"
                                    @click="detachPlateLive(plate)"
                                >
                                    <Trash2 class="size-3" />
                                    {{ t('customers.actions.detach') }}
                                </button>
                            </div>
                            <p v-if="editPlates.length === 0" class="text-xs text-slate-400">{{ t('customers.modal.no_plates') }}</p>
                        </div>

                        <!-- Pending plates (create mode) -->
                        <div v-else-if="pendingPlates.length > 0" class="space-y-2">
                            <div v-for="plate in pendingPlates" :key="plate" class="flex items-center justify-between rounded-md bg-white px-3 py-2 border border-slate-200">
                                <span class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700">
                                    <Car class="size-4 text-slate-400" />
                                    {{ plate }}
                                </span>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
                                    @click="removePendingPlate(plate)"
                                >
                                    <Trash2 class="size-3" />
                                    {{ t('customers.actions.remove') }}
                                </button>
                            </div>
                        </div>

                        <!-- New plate input -->
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <label class="block text-xs font-medium text-slate-600">{{ t('customers.modal.add_plate_label') }}</label>
                                <input
                                    v-model="newPlateInput"
                                    type="text"
                                    :placeholder="t('customers.modal.add_plate_placeholder')"
                                    class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    @keyup.enter.prevent="modalMode === 'create' ? addPendingPlate() : attachPlateLive()"
                                >
                            </div>
                            <button
                                v-if="modalMode === 'create'"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                                @click="addPendingPlate"
                            >
                                <Plus class="size-3.5" />
                                {{ t('customers.actions.add_plate') }}
                            </button>
                            <button
                                v-else
                                type="button"
                                :disabled="modalBusy"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-teal-300 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:opacity-50"
                                @click="attachPlateLive"
                            >
                                <Plus class="size-3.5" />
                                {{ t('customers.actions.attach_plate') }}
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            @click="modalOpen = false"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="modalBusy"
                            class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50"
                        >
                            {{ modalBusy ? t('common.saving') : t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================= DELETE CONFIRM ================== -->
        <div v-if="confirmDelete" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('customers.delete.title') }}</h2>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600">
                        {{ t('customers.delete.confirm', { name: confirmDelete.name }) }}
                    </p>
                    <div v-if="deleteError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ deleteError }}
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            @click="confirmDelete = null"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            :disabled="deleteBusy"
                            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50"
                            @click="performDelete"
                        >
                            {{ deleteBusy ? t('common.deleting') : t('customers.actions.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= LOYALTY CONFIG MODAL ================== -->
        <div v-if="configModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('loyalty.config.title') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ t('loyalty.config.hint') }}</p>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitLoyaltyConfig">
                    <div v-if="configError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ configError }}
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('loyalty.config.points_per_omr') }}</label>
                        <input
                            v-model.number="configForm.points_per_omr"
                            type="number"
                            min="0"
                            max="1000"
                            class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        >
                        <p class="mt-1 text-xs text-slate-500">{{ t('loyalty.config.points_per_omr_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('loyalty.config.baisas_per_point') }}</label>
                        <input
                            v-model.number="configForm.baisas_per_point"
                            type="number"
                            min="0"
                            max="10000"
                            class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        >
                        <p class="mt-1 text-xs text-slate-500">{{ t('loyalty.config.baisas_per_point_hint') }}</p>
                    </div>
                    <label class="flex items-center gap-2">
                        <input v-model="configForm.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        <span class="text-sm font-medium text-slate-700">{{ t('loyalty.config.is_active') }}</span>
                    </label>
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="configModalOpen = false">
                            {{ t('common.cancel') }}
                        </button>
                        <button type="submit" :disabled="configBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">
                            {{ configBusy ? t('common.saving') : t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================= CUSTOMER LOYALTY MODAL ================== -->
        <div v-if="loyaltyModalOpen && loyaltyTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('loyalty.customer.title') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ loyaltyTarget.name }} · <span class="font-mono">{{ loyaltyTarget.phone }}</span></p>
                </div>

                <div v-if="loyaltyError" class="mx-6 mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ loyaltyError }}
                </div>

                <div v-if="!loyaltySummary" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else class="space-y-6 p-6">
                    <!-- Balance summary -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-amber-700">
                                <Coins class="size-4" />
                                {{ t('loyalty.customer.points_balance') }}
                            </div>
                            <p class="mt-2 text-2xl font-semibold text-amber-900">{{ loyaltySummary.customer.points_balance }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                <Wallet class="size-4" />
                                {{ t('loyalty.customer.wallet_balance') }}
                            </div>
                            <p class="mt-2 text-2xl font-semibold font-mono text-emerald-900">{{ loyaltySummary.customer.wallet_balance }}</p>
                        </div>
                    </div>

                    <!-- Point adjust form -->
                    <section v-if="canLoyaltyManage" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <h3 class="text-sm font-semibold text-slate-700 inline-flex items-center gap-2">
                            <Coins class="size-4 text-amber-600" />
                            {{ t('loyalty.customer.adjust_points') }}
                        </h3>
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model.number="pointForm.delta" type="number" :placeholder="t('loyalty.customer.delta_placeholder')" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <input v-model="pointForm.reason" type="text" :placeholder="t('loyalty.customer.reason_placeholder')" class="col-span-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div class="flex justify-end">
                            <button type="button" :disabled="loyaltyBusy || pointForm.delta === 0 || !pointForm.reason" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-50" @click="submitPointAdjust">
                                {{ t('loyalty.actions.apply') }}
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500">{{ t('loyalty.customer.points_hint') }}</p>
                    </section>

                    <!-- Wallet form -->
                    <section v-if="canLoyaltyManage" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <h3 class="text-sm font-semibold text-slate-700 inline-flex items-center gap-2">
                            <Wallet class="size-4 text-emerald-600" />
                            {{ t('loyalty.customer.wallet_actions') }}
                        </h3>
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model="walletForm.amount" type="text" inputmode="decimal" :placeholder="t('loyalty.customer.amount_placeholder')" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            <input v-model="walletForm.reason" type="text" :placeholder="t('loyalty.customer.reason_placeholder')" class="col-span-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" :disabled="loyaltyBusy || parseFloat(walletForm.amount) <= 0" class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-50" @click="submitWalletTopup">
                                {{ t('loyalty.actions.topup') }}
                            </button>
                            <button type="button" :disabled="loyaltyBusy || parseFloat(walletForm.amount) === 0 || !walletForm.reason" class="rounded-lg bg-slate-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-700 disabled:opacity-50" @click="submitWalletAdjust">
                                {{ t('loyalty.actions.adjust') }}
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-500">{{ t('loyalty.customer.wallet_hint') }}</p>
                    </section>

                    <!-- Recent point entries -->
                    <section>
                        <h3 class="text-sm font-semibold text-slate-700 mb-2">{{ t('loyalty.customer.recent_points') }}</h3>
                        <div v-if="loyaltySummary.recent_points.length === 0" class="text-xs text-slate-400 italic">{{ t('loyalty.customer.no_entries') }}</div>
                        <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
                            <li v-for="entry in loyaltySummary.recent_points" :key="entry.id" class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                <span class="flex flex-col">
                                    <span class="font-semibold text-slate-700">{{ entry.entry_type }} · {{ entry.points_delta > 0 ? '+' : '' }}{{ entry.points_delta }}</span>
                                    <span class="text-slate-500">{{ entry.reason ?? '—' }}</span>
                                </span>
                                <span class="font-mono text-slate-400">{{ entry.balance_after }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Recent wallet entries -->
                    <section>
                        <h3 class="text-sm font-semibold text-slate-700 mb-2">{{ t('loyalty.customer.recent_wallet') }}</h3>
                        <div v-if="loyaltySummary.recent_wallet.length === 0" class="text-xs text-slate-400 italic">{{ t('loyalty.customer.no_entries') }}</div>
                        <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
                            <li v-for="entry in loyaltySummary.recent_wallet" :key="entry.id" class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                <span class="flex flex-col">
                                    <span class="font-semibold text-slate-700">{{ entry.entry_type }} · {{ parseFloat(entry.amount_delta) > 0 ? '+' : '' }}{{ entry.amount_delta }}</span>
                                    <span class="text-slate-500">{{ entry.reason ?? '—' }}</span>
                                </span>
                                <span class="font-mono text-slate-400">{{ entry.balance_after }}</span>
                            </li>
                        </ul>
                    </section>
                </div>

                <div class="border-t border-slate-200 px-6 py-4 flex justify-end">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="loyaltyModalOpen = false">
                        {{ t('common.close') }}
                    </button>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

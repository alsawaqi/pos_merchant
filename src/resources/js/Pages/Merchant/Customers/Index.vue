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

import { Car, Pencil, Plus, Trash2, Users } from 'lucide-vue-next';
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
                <div v-if="canManage">
                    <button
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
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
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
    </MerchantLayout>
</template>

<script setup lang="ts">
/**
 * Taxes — company-level tax settings.
 *
 * The merchant defines the taxes their company collects (a free-form name +
 * a percentage, e.g. "VAT" 5%, "Municipality" 2%). The Main POS fetches the
 * active set at staff login (/device/config) and adds each, as its own line,
 * on top of the order total (exclusive).
 *
 * Permission gating:
 *   - Page reachable when CatalogueView
 *   - Add / edit / delete only when CatalogueManage
 */

import { Pencil, Percent, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { createTax, deleteTax, getPurchaseTaxRecoverable, listTaxes, updatePurchaseTaxRecoverable, updateTax, type Tax } from '@/lib/api/taxes';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.CatalogueManage));

const taxes = ref<Tax[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

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
    return t('taxes.save_failed');
}

async function fetchTaxes(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await listTaxes();
        taxes.value = res.data;
    } catch (e) {
        loadError.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchTaxes);

// PT — the purchase-tax-recoverable company setting (lives on this page).
const recoverable = ref(false);
const recoverableSaving = ref(false);
const recoverableError = ref<string | null>(null);
async function fetchRecoverable(): Promise<void> {
    try {
        recoverable.value = (await getPurchaseTaxRecoverable()).data.purchase_tax_recoverable;
    } catch { /* default false */ }
}
async function toggleRecoverable(value: boolean): Promise<void> {
    if (!canManage.value || recoverableSaving.value) {
        return;
    }
    recoverableSaving.value = true;
    recoverableError.value = null;
    try {
        recoverable.value = (await updatePurchaseTaxRecoverable(value)).data.purchase_tax_recoverable;
    } catch (e) {
        recoverableError.value = apiErrorMessage(e);
    } finally {
        recoverableSaving.value = false;
    }
}
onMounted(fetchRecoverable);

// ---- create / edit modal ----
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalTarget = ref<Tax | null>(null);
const form = reactive<{ name: string; name_ar: string; rate_percent: string; is_active: boolean }>({
    name: '',
    name_ar: '',
    rate_percent: '',
    is_active: true,
});

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    form.name = '';
    form.name_ar = '';
    form.rate_percent = '';
    form.is_active = true;
    modalError.value = null;
    modalOpen.value = true;
}

function openEdit(tax: Tax): void {
    modalMode.value = 'edit';
    modalTarget.value = tax;
    form.name = tax.name;
    form.name_ar = tax.name_ar ?? '';
    form.rate_percent = tax.rate_percent;
    form.is_active = tax.is_active;
    modalError.value = null;
    modalOpen.value = true;
}

async function save(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    const payload = {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        // Send the raw string; Laravel's numeric rule accepts "5" / "5.5", and
        // an empty value trips the required rule → a clean 422.
        rate_percent: form.rate_percent,
        is_active: form.is_active,
    };
    try {
        if (modalMode.value === 'create') {
            await createTax(payload);
        } else if (modalTarget.value) {
            await updateTax(modalTarget.value.uuid, payload);
        }
        modalOpen.value = false;
        await fetchTaxes();
    } catch (e) {
        modalError.value = apiErrorMessage(e);
    } finally {
        modalBusy.value = false;
    }
}

// ---- delete ----
const deleteTarget = ref<Tax | null>(null);
const deleteBusy = ref(false);

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) {
        return;
    }
    deleteBusy.value = true;
    try {
        await deleteTax(deleteTarget.value.uuid);
        deleteTarget.value = null;
        await fetchTaxes();
    } catch (e) {
        modalError.value = apiErrorMessage(e);
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-4xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">{{ t('taxes.title') }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('taxes.subtitle') }}</p>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('taxes.add') }}
                </button>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <!-- PT — whether tracked purchase/input tax is recoverable. -->
            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        :checked="recoverable"
                        :disabled="!canManage || recoverableSaving"
                        class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200 disabled:opacity-50"
                        @change="toggleRecoverable(($event.target as HTMLInputElement).checked)"
                    >
                    <span>
                        <span class="block text-sm font-semibold text-slate-800">{{ t('taxes.recoverable.title') }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ t('taxes.recoverable.hint') }}</span>
                        <span v-if="recoverableError" class="mt-1 block text-xs text-rose-600">{{ recoverableError }}</span>
                    </span>
                </label>
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">…</div>
                <div v-else-if="taxes.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <Percent class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('taxes.empty_state') }}</p>
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('taxes.table.name') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('taxes.table.rate') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('taxes.table.status') }}</th>
                            <th class="px-4 py-3 text-end font-semibold">{{ t('taxes.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="tax in taxes" :key="tax.uuid" class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 font-medium text-slate-900">
                                {{ tax.name }}
                                <span v-if="tax.name_ar" class="ms-2 text-xs text-slate-400">{{ tax.name_ar }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ tax.rate_percent }}%</td>
                            <td class="px-4 py-3">
                                <span
                                    :class="tax.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                >
                                    {{ tax.is_active ? t('taxes.statuses.active') : t('taxes.statuses.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="canManage" class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                                        :title="t('taxes.edit')"
                                        @click="openEdit(tax)"
                                    >
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg border border-slate-200 text-rose-600 transition hover:bg-rose-50"
                                        :title="t('taxes.delete')"
                                        @click="deleteTarget = tax"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- create / edit modal -->
        <BaseModal
            v-if="modalOpen"
            :title="modalMode === 'create' ? t('taxes.modal.create_title') : t('taxes.modal.edit_title')"
            size="md"
            :loading="modalBusy"
            @close="modalOpen = false"
        >
            <div class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('taxes.fields.name') }}</span>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="64"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('taxes.fields.name_ar') }}</span>
                    <input
                        v-model="form.name_ar"
                        type="text"
                        maxlength="64"
                        dir="rtl"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('taxes.fields.rate_percent') }}</span>
                    <input
                        v-model="form.rate_percent"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="flex items-center gap-3">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                    >
                    <span class="text-sm font-medium text-slate-700">{{ t('taxes.fields.is_active') }}</span>
                </label>
                <p v-if="modalError" class="text-sm text-rose-600">{{ modalError }}</p>
            </div>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        :disabled="modalBusy"
                        @click="modalOpen = false"
                    >
                        {{ t('taxes.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60"
                        :disabled="modalBusy"
                        @click="save"
                    >
                        {{ t('taxes.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- delete confirm -->
        <BaseModal
            v-if="deleteTarget"
            :title="t('taxes.delete')"
            size="sm"
            :loading="deleteBusy"
            @close="deleteTarget = null"
        >
            <p class="text-sm text-slate-600">{{ t('taxes.delete_confirm', { name: deleteTarget.name }) }}</p>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        :disabled="deleteBusy"
                        @click="deleteTarget = null"
                    >
                        {{ t('taxes.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
                        :disabled="deleteBusy"
                        @click="confirmDelete"
                    >
                        {{ t('taxes.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

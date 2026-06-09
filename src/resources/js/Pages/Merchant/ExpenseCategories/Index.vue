<script setup lang="ts">
/**
 * Expense categories — company-level expense category settings.
 *
 * The merchant defines the categories their team picks when logging expenses
 * (a free-form name + an auto-generated, immutable `key` slug). The Main POS
 * fetches the active set via /device/config; logged expenses store the `key`.
 *
 * Permission gating:
 *   - Page reachable when ExpensesView (server-gated on expenses.view)
 *   - Add / edit / delete only when ExpensesManage
 */

import { Pencil, Plus, Tags, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createExpenseCategory,
    deleteExpenseCategory,
    listExpenseCategories,
    updateExpenseCategory,
    type ExpenseCategory,
} from '@/lib/api/expenseCategories';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.ExpensesManage));

const categories = ref<ExpenseCategory[]>([]);
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
    return t('expense_categories.save_failed');
}

async function fetchCategories(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await listExpenseCategories();
        categories.value = res.data;
    } catch (e) {
        loadError.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(fetchCategories);

// ---- create / edit modal ----
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalTarget = ref<ExpenseCategory | null>(null);
const form = reactive<{ name: string; name_ar: string; is_active: boolean }>({
    name: '',
    name_ar: '',
    is_active: true,
});

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    form.name = '';
    form.name_ar = '';
    form.is_active = true;
    modalError.value = null;
    modalOpen.value = true;
}

function openEdit(category: ExpenseCategory): void {
    modalMode.value = 'edit';
    modalTarget.value = category;
    form.name = category.name;
    form.name_ar = category.name_ar ?? '';
    form.is_active = category.is_active;
    modalError.value = null;
    modalOpen.value = true;
}

async function save(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    const payload = {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        is_active: form.is_active,
    };
    try {
        if (modalMode.value === 'create') {
            await createExpenseCategory(payload);
        } else if (modalTarget.value) {
            await updateExpenseCategory(modalTarget.value.uuid, payload);
        }
        modalOpen.value = false;
        await fetchCategories();
    } catch (e) {
        modalError.value = apiErrorMessage(e);
    } finally {
        modalBusy.value = false;
    }
}

// ---- delete ----
const deleteTarget = ref<ExpenseCategory | null>(null);
const deleteBusy = ref(false);

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) {
        return;
    }
    deleteBusy.value = true;
    try {
        await deleteExpenseCategory(deleteTarget.value.uuid);
        deleteTarget.value = null;
        await fetchCategories();
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
                    <h1 class="text-2xl font-bold text-slate-900">{{ t('expense_categories.title') }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('expense_categories.subtitle') }}</p>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('expense_categories.add') }}
                </button>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">…</div>
                <div v-else-if="categories.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <Tags class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('expense_categories.empty_state') }}</p>
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('expense_categories.table.name') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('expense_categories.table.key') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ t('expense_categories.table.status') }}</th>
                            <th class="px-4 py-3 text-end font-semibold">{{ t('expense_categories.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="category in categories" :key="category.uuid" class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 font-medium text-slate-900">
                                {{ category.name }}
                                <span v-if="category.name_ar" class="ms-2 text-xs text-slate-400">{{ category.name_ar }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ category.key }}</td>
                            <td class="px-4 py-3">
                                <span
                                    :class="category.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                >
                                    {{ category.is_active ? t('expense_categories.statuses.active') : t('expense_categories.statuses.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="canManage" class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                                        :title="t('expense_categories.edit')"
                                        @click="openEdit(category)"
                                    >
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg border border-slate-200 text-rose-600 transition hover:bg-rose-50"
                                        :title="t('expense_categories.delete')"
                                        @click="deleteTarget = category"
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
            :title="modalMode === 'create' ? t('expense_categories.modal.create_title') : t('expense_categories.modal.edit_title')"
            size="md"
            :loading="modalBusy"
            @close="modalOpen = false"
        >
            <div class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('expense_categories.fields.name') }}</span>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="64"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('expense_categories.fields.name_ar') }}</span>
                    <input
                        v-model="form.name_ar"
                        type="text"
                        maxlength="64"
                        dir="rtl"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="flex items-center gap-3">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                    >
                    <span class="text-sm font-medium text-slate-700">{{ t('expense_categories.fields.is_active') }}</span>
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
                        {{ t('expense_categories.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60"
                        :disabled="modalBusy"
                        @click="save"
                    >
                        {{ t('expense_categories.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- delete confirm -->
        <BaseModal
            v-if="deleteTarget"
            :title="t('expense_categories.delete')"
            size="sm"
            :loading="deleteBusy"
            @close="deleteTarget = null"
        >
            <p class="text-sm text-slate-600">{{ t('expense_categories.delete_confirm', { name: deleteTarget.name }) }}</p>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        :disabled="deleteBusy"
                        @click="deleteTarget = null"
                    >
                        {{ t('expense_categories.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
                        :disabled="deleteBusy"
                        @click="confirmDelete"
                    >
                        {{ t('expense_categories.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

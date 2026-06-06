<script setup lang="ts">
/**
 * Expenses — Phase 6 backfill (blueprint §5.10).
 *
 * The merchant's review surface for POS-captured expenses. Lists
 * expenses with status/category/branch/date filters; managers can
 * log (back-office), approve (review, optional annotation), or
 * reject (with a required reason).
 *
 * Until the POS app exists (Phase 9) the "Log expense" button is
 * the only way rows arrive; once the POS sync feed lands, rows
 * appear here in the `recorded` state automatically.
 *
 * Permission gates:
 *   - Page reachable when ExpensesView
 *   - Log / Review / Reject buttons only when ExpensesManage
 */

import { Receipt, Plus, Check, Ban, Filter } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    listExpenses,
    logExpense,
    reviewExpense,
    rejectExpense,
    EXPENSE_CATEGORIES,
    EXPENSE_STATUSES,
    type Expense,
    type ExpenseCategory,
    type ExpenseStatus,
    type PaginationMeta,
} from '@/lib/api/expenses';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can('expenses.manage'));

const expenses = ref<Expense[]>([]);
const meta = ref<PaginationMeta | null>(null);
const branches = ref<Branch[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const filters = reactive<{
    status: ExpenseStatus | '';
    category: ExpenseCategory | '';
    branch_id: number | null;
    date_from: string;
    date_to: string;
    page: number;
}>({ status: '', category: '', branch_id: null, date_from: '', date_to: '', page: 1 });

async function fetchExpenses(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await listExpenses({
            status: filters.status,
            category: filters.category,
            branch_id: filters.branch_id,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            page: filters.page,
            per_page: 25,
        });
        expenses.value = r.data;
        meta.value = r.meta;
    } catch (err) {
        error.value = err instanceof ApiError ? `HTTP ${err.status}` : t('expenses.load_failed');
    } finally {
        loading.value = false;
    }
}

function applyFilters(): void {
    filters.page = 1;
    void fetchExpenses();
}

function goPage(page: number): void {
    filters.page = page;
    void fetchExpenses();
}

onMounted(async () => {
    try {
        branches.value = (await listBranches()).data;
    } catch (err) {
        if (!(err instanceof ApiError)) throw err;
    }
    void fetchExpenses();
});

// ---- Log modal --------------------------------------------------
const logOpen = ref(false);
const logBusy = ref(false);
const logError = ref<string | null>(null);
const logForm = reactive<{ branch_id: number | null; category: ExpenseCategory; amount: string; note: string; logged_at: string }>({
    branch_id: null,
    category: 'utilities',
    amount: '',
    note: '',
    logged_at: '',
});

function openLog(): void {
    logForm.branch_id = branches.value[0]?.id ?? null;
    logForm.category = 'utilities';
    logForm.amount = '';
    logForm.note = '';
    logForm.logged_at = new Date().toISOString().slice(0, 10);
    logError.value = null;
    logOpen.value = true;
}

async function submitLog(): Promise<void> {
    // branch_id null = a general / company-wide expense (allowed).
    logBusy.value = true;
    logError.value = null;
    try {
        await logExpense({
            branch_id: logForm.branch_id,
            category: logForm.category,
            amount: logForm.amount,
            note: logForm.note || null,
            logged_at: logForm.logged_at || undefined,
        });
        logOpen.value = false;
        applyFilters();
    } catch (err) {
        logError.value = err instanceof ApiError && err.isValidationError()
            ? Object.values(err.payload.errors)[0]?.[0] ?? t('expenses.save_failed')
            : t('expenses.save_failed');
    } finally {
        logBusy.value = false;
    }
}

// ---- Review / Reject modal --------------------------------------
const reviewOpen = ref(false);
const reviewTarget = ref<Expense | null>(null);
const reviewNote = ref('');
const reviewBusy = ref(false);

function openReview(e: Expense): void {
    reviewTarget.value = e;
    reviewNote.value = '';
    reviewOpen.value = true;
}

async function submitReview(): Promise<void> {
    if (!reviewTarget.value) return;
    reviewBusy.value = true;
    try {
        await reviewExpense(reviewTarget.value.uuid, reviewNote.value || undefined);
        reviewOpen.value = false;
        void fetchExpenses();
    } finally {
        reviewBusy.value = false;
    }
}

const rejectOpen = ref(false);
const rejectTarget = ref<Expense | null>(null);
const rejectNote = ref('');
const rejectBusy = ref(false);
const rejectError = ref<string | null>(null);

function openReject(e: Expense): void {
    rejectTarget.value = e;
    rejectNote.value = '';
    rejectError.value = null;
    rejectOpen.value = true;
}

async function submitReject(): Promise<void> {
    if (!rejectTarget.value) return;
    if (rejectNote.value.trim() === '') {
        rejectError.value = t('expenses.reject_modal.reason_required');
        return;
    }
    rejectBusy.value = true;
    try {
        await rejectExpense(rejectTarget.value.uuid, rejectNote.value.trim());
        rejectOpen.value = false;
        void fetchExpenses();
    } catch (err) {
        rejectError.value = err instanceof ApiError ? `HTTP ${err.status}` : t('expenses.save_failed');
    } finally {
        rejectBusy.value = false;
    }
}

// ---- Display helpers --------------------------------------------
function statusBadgeClass(s: ExpenseStatus): string {
    if (s === 'reviewed') return 'bg-emerald-100 text-emerald-700';
    if (s === 'rejected') return 'bg-rose-100 text-rose-700';
    return 'bg-amber-100 text-amber-700';
}

function fmt(dt: string | null): string {
    if (!dt) return '—';
    return dt.replace('T', ' ').slice(0, 16);
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">{{ t('expenses.section_label') }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ t('expenses.title') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ t('expenses.subtitle') }}</p>
                </div>
                <div v-if="canManage">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-700"
                        @click="openLog"
                    >
                        <Plus class="size-4" />
                        {{ t('expenses.actions.log') }}
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-500">
                    {{ t('expenses.filters.status') }}
                    <select v-model="filters.status" class="w-36 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                        <option value="">{{ t('expenses.filters.all') }}</option>
                        <option v-for="s in EXPENSE_STATUSES" :key="s" :value="s">{{ t(`expenses.status.${s}`) }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-500">
                    {{ t('expenses.filters.category') }}
                    <select v-model="filters.category" class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                        <option value="">{{ t('expenses.filters.all') }}</option>
                        <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ t(`expenses.category.${c}`) }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-500">
                    {{ t('expenses.filters.branch') }}
                    <select v-model="filters.branch_id" class="w-44 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900">
                        <option :value="null">{{ t('expenses.filters.all') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-500">
                    {{ t('expenses.filters.date_from') }}
                    <input type="date" v-model="filters.date_from" class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-500">
                    {{ t('expenses.filters.date_to') }}
                    <input type="date" v-model="filters.date_to" class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900" />
                </label>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    @click="applyFilters"
                >
                    <Filter class="size-4" />
                    {{ t('expenses.filters.apply') }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <!-- Table -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">{{ t('common.loading') }}</div>
                <div v-else-if="expenses.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Receipt class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('expenses.empty') }}</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.date') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.branch') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.category') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.amount') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.logged_by') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('expenses.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="e in expenses" :key="e.id" class="align-top transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-sm tabular-nums text-slate-700">{{ fmt(e.logged_at) }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ e.branch_name ?? t('expenses.general') }}</td>
                                <td class="px-5 py-4 text-sm capitalize text-slate-700">{{ t(`expenses.category.${e.category}`) }}</td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums text-slate-900">{{ e.amount }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    {{ e.logged_by_name ?? '—' }}
                                    <span v-if="e.note" class="block text-xs text-slate-400">{{ e.note }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusBadgeClass(e.status)">
                                        {{ t(`expenses.status.${e.status}`) }}
                                    </span>
                                    <span v-if="e.review_note" class="mt-1 block text-xs text-slate-400">{{ e.review_note }}</span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div v-if="canManage && e.status !== 'rejected'" class="inline-flex items-center gap-2">
                                        <button
                                            v-if="e.status === 'recorded'"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                            @click="openReview(e)"
                                        >
                                            <Check class="size-3.5" /> {{ t('expenses.actions.review') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                                            @click="openReject(e)"
                                        >
                                            <Ban class="size-3.5" /> {{ t('expenses.actions.reject') }}
                                        </button>
                                    </div>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <span>{{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }}</span>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page <= 1 || loading" @click="goPage(meta!.current_page - 1)">Prev</button>
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page >= meta.last_page || loading" @click="goPage(meta!.current_page + 1)">Next</button>
                    </div>
                </div>
            </section>
        </section>

        <!-- Log modal -->
        <BaseModal v-if="logOpen" :title="t('expenses.log_modal.title')" size="md" :loading="logBusy" @close="logOpen = false">
            <div class="space-y-3">
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('expenses.log_modal.branch') }}
                    <select v-model="logForm.branch_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option :value="null">{{ t('expenses.log_modal.general') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('expenses.log_modal.category') }}
                    <select v-model="logForm.category" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ t(`expenses.category.${c}`) }}</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('expenses.log_modal.amount') }}
                    <input v-model="logForm.amount" type="text" inputmode="decimal" placeholder="0.000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm tabular-nums" />
                </label>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('expenses.log_modal.date') }}
                    <input v-model="logForm.logged_at" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('expenses.log_modal.note') }}
                    <textarea v-model="logForm.note" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </label>
                <p v-if="logError" class="text-sm font-semibold text-rose-600">{{ logError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="logOpen = false">{{ t('common.cancel') }}</button>
                    <button type="button" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50" :disabled="logBusy" @click="submitLog">{{ logBusy ? t('common.saving') : t('expenses.log_modal.submit') }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- Review modal -->
        <BaseModal v-if="reviewOpen" :title="t('expenses.review_modal.title')" size="md" :loading="reviewBusy" @close="reviewOpen = false">
            <label class="block text-sm font-semibold text-slate-700">
                {{ t('expenses.review_modal.note_optional') }}
                <textarea v-model="reviewNote" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="reviewOpen = false">{{ t('common.cancel') }}</button>
                    <button type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" :disabled="reviewBusy" @click="submitReview">{{ reviewBusy ? t('common.saving') : t('expenses.review_modal.submit') }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- Reject modal -->
        <BaseModal v-if="rejectOpen" :title="t('expenses.reject_modal.title')" size="md" :loading="rejectBusy" @close="rejectOpen = false">
            <label class="block text-sm font-semibold text-slate-700">
                {{ t('expenses.reject_modal.reason') }}
                <textarea v-model="rejectNote" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </label>
            <p v-if="rejectError" class="mt-2 text-sm font-semibold text-rose-600">{{ rejectError }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="rejectOpen = false">{{ t('common.cancel') }}</button>
                    <button type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50" :disabled="rejectBusy" @click="submitReject">{{ rejectBusy ? t('common.saving') : t('expenses.reject_modal.submit') }}</button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

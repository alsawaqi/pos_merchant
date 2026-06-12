<script setup lang="ts">
/**
 * Kitchen production history (P-G1) — READ-ONLY.
 *
 * Every cooked-product batch the kitchen ran: who started/finished it,
 * what was made, the locked recipe consumption vs the declared extras
 * (the kitchen variance view), and how long the batch took. Batches are
 * written exclusively by the POS device through pos_api — this page
 * audits, it never edits.
 *
 * Server gate: production.view (403 otherwise); the sidebar entry is
 * hidden without it too.
 */

import { ChefHat, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import { listProductions, type Production } from '@/lib/api/productions';

const { t, locale } = useI18n();

const branches = ref<Branch[]>([]);
const rows = ref<Production[]>([]);
const expanded = ref<Set<number>>(new Set());

const loading = ref(true);
const loadError = ref<string | null>(null);

const page = ref(1);
const lastPage = ref(1);
const total = ref(0);

// Filters. Dates filter on started_at (inclusive).
const branchUuid = ref('');
const status = ref('');
const from = ref('');
const to = ref('');

async function fetchBranches(): Promise<void> {
    try {
        const r = await listBranches();
        branches.value = r.data;
    } catch {
        // Branch filter just stays empty — the table itself still loads.
    }
}

async function fetchRows(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await listProductions({
            branch_uuid: branchUuid.value || undefined,
            status: status.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            page: page.value,
        });
        rows.value = res.data;
        lastPage.value = res.last_page;
        total.value = res.total;
        expanded.value = new Set();
    } catch (e) {
        loadError.value = e instanceof ApiError ? (e.message || t('production.load_failed')) : t('production.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void fetchBranches();
    void fetchRows();
});

// Any filter change restarts from page 1.
watch([branchUuid, status, from, to], () => {
    page.value = 1;
    void fetchRows();
});

watch(page, () => { void fetchRows(); });

function toggleExpanded(id: number): void {
    const next = new Set(expanded.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    expanded.value = next;
}

const isAr = computed(() => locale.value === 'ar');

function productName(p: Production): string {
    return (isAr.value ? p.product.name_ar : null) ?? p.product.name ?? '—';
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString(isAr.value ? 'ar' : 'en-GB', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    });
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (m >= 60) {
        const h = Math.floor(m / 60);
        return `${h}h ${m % 60}m`;
    }
    return `${m}m ${s.toString().padStart(2, '0')}s`;
}

/** Trim trailing zeros off a decimal string ("2.500" -> "2.5", "3.000" -> "3"). */
function trimQty(q: string): string {
    return q.includes('.') ? q.replace(/\.?0+$/, '') : q;
}

const statusClasses: Record<Production['status'], string> = {
    in_progress: 'bg-amber-50 text-amber-700',
    finished: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-rose-50 text-rose-600',
};
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-6xl">
            <div class="flex items-center gap-3">
                <ChefHat class="size-7 text-teal-600" />
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">{{ t('production.title') }}</h1>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t('production.subtitle') }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="mt-6 flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('production.filters.branch') }}</span>
                    <select v-model="branchUuid" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <option value="">{{ t('production.filters.all_branches') }}</option>
                        <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('production.filters.status') }}</span>
                    <select v-model="status" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <option value="">{{ t('production.filters.all_statuses') }}</option>
                        <option value="in_progress">{{ t('production.status.in_progress') }}</option>
                        <option value="finished">{{ t('production.status.finished') }}</option>
                        <option value="cancelled">{{ t('production.status.cancelled') }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('production.filters.from') }}</span>
                    <input v-model="from" type="date" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('production.filters.to') }}</span>
                    <input v-model="to" type="date" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <span class="ms-auto pb-2 text-xs text-slate-400">{{ t('production.total_count', { count: total }) }}</span>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="rows.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <ChefHat class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('production.empty_state') }}</p>
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('production.columns.started') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('production.columns.product') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('production.columns.branch') }}</th>
                            <th class="px-4 py-2.5 text-end font-semibold">{{ t('production.columns.quantity') }}</th>
                            <th class="px-4 py-2.5 text-center font-semibold">{{ t('production.columns.status') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('production.columns.by') }}</th>
                            <th class="px-4 py-2.5 text-end font-semibold">{{ t('production.columns.duration') }}</th>
                            <th class="w-10 px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-for="row in rows" :key="row.id">
                            <tr class="cursor-pointer hover:bg-slate-50/60" @click="toggleExpanded(row.id)">
                                <td class="px-4 py-2.5 tabular-nums text-slate-600">{{ formatDate(row.started_at) }}</td>
                                <td class="px-4 py-2.5 font-medium text-slate-900">{{ productName(row) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ row.branch.name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums font-semibold text-slate-900">{{ trimQty(row.quantity) }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span :class="statusClasses[row.status]" class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase">
                                        {{ t(`production.status.${row.status}`) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ row.started_by ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-slate-600">{{ formatDuration(row.duration_seconds) }}</td>
                                <td class="px-4 py-2.5 text-center text-slate-400">
                                    <ChevronUp v-if="expanded.has(row.id)" class="size-4" />
                                    <ChevronDown v-else class="size-4" />
                                </td>
                            </tr>
                            <tr v-if="expanded.has(row.id)" class="bg-slate-50/70">
                                <td colspan="8" class="px-6 py-4">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <!-- Ingredient lines: locked recipe vs declared extras. -->
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('production.detail.ingredients') }}</p>
                                            <ul class="mt-2 space-y-1">
                                                <li v-for="(line, idx) in row.lines" :key="idx" class="flex items-center gap-2 text-sm">
                                                    <span class="text-slate-700">{{ (isAr ? line.ingredient_name_ar : null) ?? line.ingredient_name ?? '—' }}</span>
                                                    <span class="tabular-nums text-slate-500">{{ trimQty(line.quantity) }} {{ line.unit }}</span>
                                                    <span v-if="line.is_extra" class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700">{{ t('production.detail.extra') }}</span>
                                                </li>
                                                <li v-if="row.lines.length === 0" class="text-xs italic text-slate-400">{{ t('production.detail.no_lines') }}</li>
                                            </ul>
                                        </div>
                                        <!-- Lifecycle -->
                                        <div class="space-y-1 text-sm text-slate-600">
                                            <p>
                                                <span class="font-medium text-slate-700">{{ t('production.detail.started') }}:</span>
                                                {{ formatDate(row.started_at) }}
                                                <template v-if="row.started_by"> — {{ row.started_by }}</template>
                                            </p>
                                            <p v-if="row.finished_at">
                                                <span class="font-medium text-slate-700">{{ t('production.detail.finished') }}:</span>
                                                {{ formatDate(row.finished_at) }}
                                                <template v-if="row.finished_by"> — {{ row.finished_by }}</template>
                                            </p>
                                            <p v-if="row.expires_at">
                                                <span class="font-medium text-slate-700">{{ t('production.detail.good_range') }}:</span>
                                                {{ formatDate(row.finished_at) }} → {{ formatDate(row.expires_at) }}
                                            </p>
                                            <p v-if="row.cancelled_at">
                                                <span class="font-medium text-slate-700">{{ t('production.detail.cancelled') }}:</span>
                                                {{ formatDate(row.cancelled_at) }}
                                                <template v-if="row.cancelled_by"> — {{ row.cancelled_by }}</template>
                                            </p>
                                            <p v-if="row.cancel_approved_by">
                                                <span class="font-medium text-slate-700">{{ t('production.detail.approved_by') }}:</span>
                                                {{ row.cancel_approved_by }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Pager -->
            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                    :disabled="page <= 1 || loading"
                    @click="page = page - 1"
                >
                    {{ t('common.previous') }}
                </button>
                <span class="text-sm tabular-nums text-slate-500">{{ page }} / {{ lastPage }}</span>
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                    :disabled="page >= lastPage || loading"
                    @click="page = page + 1"
                >
                    {{ t('common.next') }}
                </button>
            </div>
        </div>
    </MerchantLayout>
</template>

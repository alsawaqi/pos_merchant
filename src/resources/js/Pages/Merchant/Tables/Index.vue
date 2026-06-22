<script setup lang="ts">
/**
 * Tables overview (v2) — the dine-in "record of the tables".
 *
 * Per branch, every table with its window aggregates: how many times it
 * was sat at, total + average spend, average sitting duration, distinct
 * customers, and whether it's occupied right now. Click a row to drill
 * into that table's full record (/tables/:uuid). Floor-plan tiles deep-
 * link to the same detail page.
 *
 * reports.view gated server-side; the nav entry is hidden without it.
 */

import { Armchair, Building2, CalendarRange, Clock, Receipt, CircleDot } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import { listBranches, type Branch } from '@/lib/api/branches';
import { fetchTablesOverview, type TableOverviewPayload, type TableOverviewRow } from '@/lib/api/tableInsights';
import { fmtDuration } from '@/lib/duration';

const { t } = useI18n();
const router = useRouter();

const branches = ref<Branch[]>([]);
const selectedBranchId = ref<number | null>(null);
const payload = ref<TableOverviewPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

type SortKey = 'revenue' | 'sittings' | 'avg_duration_seconds' | 'label';
const sortKey = ref<SortKey>('revenue');

// Default window: trailing 90 days.
function isoDate(d: Date): string {
    return d.toISOString().slice(0, 10);
}
const todayDate = new Date();
const fromDate = new Date(todayDate);
fromDate.setDate(fromDate.getDate() - 89);
const dateFrom = ref(isoDate(fromDate));
const dateTo = ref(isoDate(todayDate));

function num(v: string | number | null | undefined): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

const sortedTables = computed<TableOverviewRow[]>(() => {
    const rows = [...(payload.value?.tables ?? [])];
    rows.sort((a, b) => {
        switch (sortKey.value) {
            case 'sittings': return b.sittings - a.sittings;
            case 'avg_duration_seconds': return b.avg_duration_seconds - a.avg_duration_seconds;
            case 'label': return a.label.localeCompare(b.label, undefined, { numeric: true });
            default: return num(b.revenue) - num(a.revenue);
        }
    });
    return rows;
});

// "Top tables by revenue" — only tables that actually earned, top 10.
const topChart = computed(() => {
    const rows = [...(payload.value?.tables ?? [])]
        .filter((r) => num(r.revenue) > 0)
        .sort((a, b) => num(b.revenue) - num(a.revenue))
        .slice(0, 10);
    return {
        categories: rows.map((r) => r.label),
        series: [{ name: t('tables.metrics.revenue'), data: rows.map((r) => num(r.revenue)) }],
    };
});

async function fetchBranches(): Promise<void> {
    try {
        const res = await listBranches();
        branches.value = res.data;
        if (selectedBranchId.value === null && branches.value.length > 0) {
            selectedBranchId.value = branches.value[0].id;
        }
    } catch {
        branches.value = [];
    }
}

async function fetchOverview(): Promise<void> {
    if (selectedBranchId.value === null) {
        payload.value = null;
        loading.value = false;
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const res = await fetchTablesOverview(selectedBranchId.value, {
            date_from: dateFrom.value,
            date_to: dateTo.value,
        });
        payload.value = res.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load tables';
        payload.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await fetchBranches();
    await fetchOverview();
});
// Branch changes refetch via the select's @change (no watcher, so the
// initial branch assignment in fetchBranches doesn't double-fire onMounted).

function openTable(row: TableOverviewRow): void {
    void router.push(`/tables/${row.uuid}`);
}

function statusBadgeClass(status: string | null): string {
    return status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700';
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('tables.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ t('tables.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ t('tables.subtitle') }}</p>
            </div>

            <!-- Controls: branch + date range -->
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <Building2 class="me-1 inline size-3" />{{ t('tables.branch') }}
                    </span>
                    <select v-model.number="selectedBranchId" class="mt-1 w-full min-w-56 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" @change="fetchOverview">
                        <option v-for="b in branches" :key="b.uuid" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <CalendarRange class="me-1 inline size-3" />{{ t('tables.date_from') }}
                    </span>
                    <input v-model="dateFrom" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('tables.date_to') }}</span>
                    <input v-model="dateTo" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5"
                    @click="fetchOverview"
                >
                    {{ t('tables.apply') }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <div v-if="branches.length === 0 && !loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                <Building2 class="mx-auto size-10 text-slate-300" />
                <p class="mt-3 text-sm font-medium text-slate-600">{{ t('tables.no_branches') }}</p>
            </div>

            <section v-else-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('common.loading') }}
            </section>

            <template v-else-if="payload">
                <!-- KPI strip -->
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Armchair class="size-3.5" />{{ t('tables.totals.tables') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ payload.totals.table_count }}</div>
                        <div v-if="payload.totals.occupied_now > 0" class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold text-amber-600">
                            <CircleDot class="size-3" />{{ t('tables.totals.occupied_now', { n: payload.totals.occupied_now }) }}
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Receipt class="size-3.5" />{{ t('tables.totals.sittings') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ payload.totals.sittings }}</div>
                    </div>
                    <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('tables.totals.revenue') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-teal-900">{{ payload.totals.revenue }} <span class="text-sm font-medium">OMR</span></div>
                        <div class="mt-0.5 text-xs text-teal-700">{{ t('tables.metrics.avg_spend') }}: {{ payload.totals.avg_spend }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Clock class="size-3.5" />{{ t('tables.metrics.avg_duration') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ fmtDuration(payload.totals.avg_duration_seconds) }}</div>
                    </div>
                </div>

                <!-- Top tables by revenue -->
                <ReportChart
                    type="bar"
                    :title="t('tables.charts.top_revenue')"
                    :series="topChart.series"
                    :categories="topChart.categories"
                    :height="Math.max(220, topChart.categories.length * 38)"
                    currency
                    horizontal
                    distributed
                    hide-legend
                    :empty-text="t('tables.no_data')"
                />

                <!-- Table list -->
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <header class="flex flex-col gap-2 border-b border-slate-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-base font-semibold text-slate-950">{{ t('tables.list_title') }}</h2>
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-500">
                            {{ t('tables.sort_by') }}
                            <select v-model="sortKey" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-700 focus:border-teal-500 focus:outline-none">
                                <option value="revenue">{{ t('tables.metrics.revenue') }}</option>
                                <option value="sittings">{{ t('tables.metrics.sittings') }}</option>
                                <option value="avg_duration_seconds">{{ t('tables.metrics.avg_duration') }}</option>
                                <option value="label">{{ t('tables.metrics.label') }}</option>
                            </select>
                        </label>
                    </header>

                    <div v-if="sortedTables.length === 0" class="p-10 text-center text-sm text-slate-400">{{ t('tables.no_tables') }}</div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-2 text-start">{{ t('tables.metrics.label') }}</th>
                                    <th class="px-5 py-2 text-start">{{ t('tables.metrics.floor') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.sittings') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.revenue') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.avg_spend') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.avg_duration') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.customers') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.metrics.last_used') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in sortedTables"
                                    :key="row.uuid"
                                    class="cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-teal-50/40"
                                    @click="openTable(row)"
                                >
                                    <td class="px-5 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-slate-900">{{ row.label }}</span>
                                            <span v-if="row.active_now" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-700">
                                                <CircleDot class="size-2.5" />{{ t('tables.occupied') }}
                                            </span>
                                            <span v-else class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="statusBadgeClass(row.status)">{{ t(`tables.statuses.${row.status ?? 'active'}`) }}</span>
                                        </div>
                                        <span class="text-xs text-slate-400">{{ t('tables.seats_n', { count: row.seats }) }}</span>
                                    </td>
                                    <td class="px-5 py-2.5 text-slate-600">{{ row.floor_name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-end font-semibold tabular-nums text-slate-900">{{ row.sittings }}</td>
                                    <td class="px-5 py-2.5 text-end tabular-nums text-slate-900">{{ row.revenue }}</td>
                                    <td class="px-5 py-2.5 text-end tabular-nums text-slate-600">{{ row.avg_spend }}</td>
                                    <td class="px-5 py-2.5 text-end tabular-nums text-slate-600">{{ fmtDuration(row.avg_duration_seconds) }}</td>
                                    <td class="px-5 py-2.5 text-end tabular-nums text-slate-600">{{ row.unique_customers }}</td>
                                    <td class="px-5 py-2.5 text-end text-xs text-slate-500">{{ row.last_used_at ? new Date(row.last_used_at).toLocaleDateString() : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </section>
    </MerchantLayout>
</template>

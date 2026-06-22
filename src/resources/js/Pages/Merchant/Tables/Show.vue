<script setup lang="ts">
/**
 * Table detail (v2) — one dine-in table's full professional record.
 *
 * Reached from the Tables overview or a floor-plan tile. Shows, for the
 * selected window: total sittings, revenue + average spend, average and
 * total occupancy duration, distinct customers, the busiest hour/day, a
 * revenue trend, a by-hour breakdown, the list of every sitting (click →
 * the order drawer), and the top customers who sat here.
 *
 * reports.view gated server-side.
 */

import { computed, onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
    Armchair, ArrowLeft, CalendarRange, Clock, Crown, Receipt, Users, CircleDot, Hourglass,
} from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import OrderDetailDrawer from '@/Pages/Merchant/Orders/components/OrderDetailDrawer.vue';
import { ApiError } from '@/lib/api';
import { fetchTableDetail, type TableDetailPayload } from '@/lib/api/tableInsights';
import { fmtDuration } from '@/lib/duration';

const route = useRoute();
const { t, locale } = useI18n();

const uuid = String(route.params.uuid);

const payload = ref<TableDetailPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const detailUuid = ref<string | null>(null);

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

const table = computed(() => payload.value?.table ?? null);
const summary = computed(() => payload.value?.summary ?? null);

const trendChart = computed(() => ({
    categories: (payload.value?.revenue_trend ?? []).map((d) => d.date.slice(5)),
    series: [{ name: t('tables.metrics.revenue'), data: (payload.value?.revenue_trend ?? []).map((d) => num(d.gross)) }],
}));

const hourChart = computed(() => {
    const rows = payload.value?.by_hour ?? [];
    return {
        categories: rows.map((r) => `${String(r.hour).padStart(2, '0')}`),
        series: [{ name: t('tables.metrics.sittings'), data: rows.map((r) => r.count) }],
    };
});

const hasHourData = computed(() => (payload.value?.by_hour ?? []).some((r) => r.count > 0));

function busiestHourLabel(h: number | null): string {
    if (h === null) return '—';
    const a = String(h).padStart(2, '0');
    const b = String((h + 1) % 24).padStart(2, '0');
    return `${a}:00–${b}:00`;
}

function weekdayName(wd: number | null): string {
    if (wd === null) return '—';
    // 2024-01-07 is a Sunday (weekday 0), matching the server's 0=Sun convention.
    const ref = new Date(2024, 0, 7 + wd);
    return ref.toLocaleDateString(locale.value === 'ar' ? 'ar' : 'en', { weekday: 'long' });
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function shapeLabel(shape: string | null): string {
    if (!shape) return '';
    const key = `tables.shapes.${shape}`;
    const label = t(key);
    return label !== key ? label : shape;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await fetchTableDetail(uuid, { date_from: dateFrom.value, date_to: dateTo.value });
        payload.value = res.data;
    } catch (err) {
        error.value = err instanceof ApiError
            ? (err.status === 404 ? t('tables.detail.not_found') : `HTTP ${err.status}`)
            : (err instanceof Error ? err.message : 'Failed');
        payload.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <MerchantLayout>
        <div class="max-w-6xl">
            <RouterLink to="/tables" class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('tables.detail.back') }}
            </RouterLink>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ error }}</div>

            <div v-else-if="payload && table && summary" class="space-y-6">
                <!-- Header -->
                <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-teal-500 to-indigo-500 text-white shadow-sm">
                                    <Armchair class="size-5" />
                                </div>
                                <h1 class="text-2xl font-bold text-slate-950">{{ t('tables.detail.title', { label: table.label }) }}</h1>
                                <span v-if="summary.active_now" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold uppercase text-amber-700">
                                    <CircleDot class="size-3" />{{ t('tables.occupied') }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-slate-500">
                                {{ table.floor_name ?? '—' }}<span v-if="table.branch_name"> · {{ table.branch_name }}</span>
                                · {{ t('tables.seats_n', { count: table.seats }) }}<span v-if="shapeLabel(table.shape)"> · {{ shapeLabel(table.shape) }}</span>
                            </p>
                            <p v-if="summary.first_used_at" class="mt-1 text-xs text-slate-400">
                                {{ t('tables.detail.first_used', { date: formatDateTime(summary.first_used_at) }) }}
                            </p>
                        </div>

                        <!-- Date window -->
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500"><CalendarRange class="me-1 inline size-3" />{{ t('tables.date_from') }}</span>
                                <input v-model="dateFrom" type="date" class="mt-1 rounded-lg border border-slate-200 px-2.5 py-2 text-sm focus:border-teal-500 focus:outline-none">
                            </label>
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('tables.date_to') }}</span>
                                <input v-model="dateTo" type="date" class="mt-1 rounded-lg border border-slate-200 px-2.5 py-2 text-sm focus:border-teal-500 focus:outline-none">
                            </label>
                            <button type="button" class="rounded-lg bg-slate-950 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800" @click="load">{{ t('tables.apply') }}</button>
                        </div>
                    </div>
                </header>

                <!-- KPI tiles -->
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Receipt class="size-3.5" />{{ t('tables.metrics.sittings') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ summary.sittings }}</div>
                    </div>
                    <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('tables.metrics.revenue') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-teal-900">{{ summary.revenue }}</div>
                        <div class="mt-0.5 text-[11px] font-medium text-teal-700">OMR</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('tables.metrics.avg_spend') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ summary.avg_spend }}</div>
                        <div class="mt-0.5 text-[11px] font-medium text-slate-400">OMR</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Clock class="size-3.5" />{{ t('tables.metrics.avg_duration') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ fmtDuration(summary.avg_duration_seconds) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Hourglass class="size-3.5" />{{ t('tables.metrics.total_duration') }}</div>
                        <div class="mt-2 text-xl font-bold tabular-nums text-slate-950">{{ fmtDuration(summary.total_duration_seconds) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Users class="size-3.5" />{{ t('tables.metrics.customers') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ summary.unique_customers }}</div>
                    </div>
                </div>

                <!-- Busiest hour / day -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('tables.metrics.busiest_hour') }}</div>
                        <div class="mt-1 text-lg font-semibold text-slate-900">{{ busiestHourLabel(summary.busiest_hour) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('tables.metrics.busiest_day') }}</div>
                        <div class="mt-1 text-lg font-semibold capitalize text-slate-900">{{ weekdayName(summary.busiest_weekday) }}</div>
                    </div>
                </div>

                <!-- Charts -->
                <ReportChart
                    type="line"
                    :title="t('tables.charts.revenue_trend')"
                    :series="trendChart.series"
                    :categories="trendChart.categories"
                    :height="260"
                    currency
                    hide-legend
                    :empty-text="t('tables.no_data')"
                />
                <ReportChart
                    v-if="hasHourData"
                    type="bar"
                    :title="t('tables.charts.by_hour')"
                    :series="hourChart.series"
                    :categories="hourChart.categories"
                    :height="240"
                    distributed
                    hide-legend
                    :empty-text="t('tables.no_data')"
                />

                <!-- Top customers -->
                <section v-if="payload.top_customers.length" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <Crown class="size-4 text-amber-500" />{{ t('tables.detail.top_customers') }}
                    </h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="(c, i) in payload.top_customers" :key="c.customer_id" class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <div class="flex items-center gap-2.5">
                                <span class="grid size-6 place-items-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">{{ i + 1 }}</span>
                                <div>
                                    <p class="text-sm font-medium text-slate-900">{{ c.name }}</p>
                                    <p v-if="c.phone" class="text-xs text-slate-400">{{ c.phone }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <span class="text-slate-500">{{ t('tables.detail.visits', { n: c.visits }) }}</span>
                                <span class="font-semibold tabular-nums text-slate-900">{{ c.spend }} <span class="text-xs font-normal text-slate-400">OMR</span></span>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Sittings list -->
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <Receipt class="size-4 text-slate-500" />{{ t('tables.detail.sittings_title') }}
                    </h2>
                    <div v-if="payload.sittings.length === 0" class="p-8 text-center text-sm text-slate-400">{{ t('tables.no_data') }}</div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-2 text-start">{{ t('tables.detail.cols.when') }}</th>
                                    <th class="px-5 py-2 text-start">{{ t('tables.detail.cols.duration') }}</th>
                                    <th class="px-5 py-2 text-start">{{ t('tables.detail.cols.customer') }}</th>
                                    <th class="px-5 py-2 text-start">{{ t('tables.detail.cols.staff') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.detail.cols.items') }}</th>
                                    <th class="px-5 py-2 text-end">{{ t('tables.detail.cols.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="s in payload.sittings"
                                    :key="s.order_uuid"
                                    class="cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-teal-50/40"
                                    @click="detailUuid = s.order_uuid"
                                >
                                    <td class="px-5 py-2.5 text-slate-700">{{ formatDateTime(s.opened_at) }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-slate-600">{{ fmtDuration(s.duration_seconds) }}</td>
                                    <td class="px-5 py-2.5 text-slate-700">{{ s.customer_name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-slate-600">{{ s.staff_name ?? '—' }}</td>
                                    <td class="px-5 py-2.5 text-end tabular-nums text-slate-600">{{ s.items_count }}</td>
                                    <td class="px-5 py-2.5 text-end font-semibold tabular-nums text-slate-900">{{ s.grand_total }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <OrderDetailDrawer v-model:uuid="detailUuid" />
    </MerchantLayout>
</template>

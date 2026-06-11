<script setup lang="ts">
/**
 * Sales Report page — blueprint §5.11.1.
 *
 * Renders the headline KPI grid, a set of ApexCharts visualizations
 * (v2 Step 1: P&L overview, peak-hour line, weekday bar, payment +
 * order-type donuts, top-branch ranking) and the original breakdown
 * tables underneath as the exact-figure detail.
 */

import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchSalesReport, type SalesReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import SalesHeatmap from './components/SalesHeatmap.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<SalesReportPayload>(fetchSalesReport);

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function orderTypeLabel(type: string | undefined): string {
    if (!type) return '—';
    const key = `orders.types.${type}`;
    const label = t(key);
    return label !== key ? label : type.replace(/_/g, ' ');
}

/**
 * P-F5 — raw pos_payments.method strings get a translated label
 * (e.g. 'bank_pos' → "Bank POS"); unknown methods fall back to the
 * old capitalisation so a future tender never renders blank.
 */
function methodLabel(method: string | undefined): string {
    if (!method) return '—';
    const key = `orders.payment_methods.${method}`;
    const label = t(key);
    return label !== key ? label : method.charAt(0).toUpperCase() + method.slice(1).replace(/_/g, ' ');
}

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// ---- Chart series (all derived from the live payload) ----

const pnlChart = computed(() => {
    const h = payload.value?.headline;
    if (!h) return { categories: [] as string[], series: [] as ApexSeries };
    const L = (k: string) => t(`reports.sales.headline_labels.${k}`);
    return {
        categories: [
            L('gross_sales'), L('net_sales'), L('discounts'), L('tax'),
            L('cogs'), L('operating_expenses'), L('gross_profit'), L('net_profit'),
        ],
        series: [{
            name: t('reports.shared.value'),
            data: [
                num(h.gross_sales), num(h.net_sales), num(h.discount_total), num(h.tax_total),
                num(h.cogs), num(h.operating_expenses), num(h.gross_profit), num(h.net_profit),
            ],
        }],
    };
});

const weekdayChart = computed(() => {
    const rows = payload.value?.by_weekday ?? [];
    return {
        categories: rows.map((r) => weekdayLabels[r.weekday] ?? String(r.weekday)),
        series: [{ name: t('reports.sales.headline_labels.gross_sales'), data: rows.map((r) => num(r.gross)) }] as ApexSeries,
    };
});

const paymentChart = computed(() => {
    const rows = payload.value?.by_payment_method ?? [];
    return {
        labels: rows.map((r) => methodLabel(r.method)),
        series: rows.map((r) => num(r.amount)),
    };
});

const orderTypeChart = computed(() => {
    const rows = payload.value?.by_order_type ?? [];
    return {
        labels: rows.map((r) => orderTypeLabel(r.type)),
        series: rows.map((r) => num(r.gross)),
    };
});

const branchChart = computed(() => {
    const rows = [...(payload.value?.by_branch ?? [])].sort((a, b) => num(b.gross) - num(a.gross));
    return {
        categories: rows.map((r) => r.branch_name),
        series: [{ name: t('reports.sales.headline_labels.gross_sales'), data: rows.map((r) => num(r.gross)) }] as ApexSeries,
    };
});

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell
        export-key="sales"
        :title="t('reports.sales.page_title')"
        v-model="filter"
        :loading="loading"
        :error="error"
        @run="run"
    >
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.sales.headline_labels.gross_sales'), value: payload.headline.gross_sales },
                    { label: t('reports.sales.headline_labels.net_sales'), value: payload.headline.net_sales },
                    { label: t('reports.sales.headline_labels.discounts'), value: payload.headline.discount_total },
                    { label: t('reports.sales.headline_labels.tax'), value: payload.headline.tax_total },
                    { label: t('reports.sales.headline_labels.refunds'), value: payload.headline.refunds_total },
                    { label: t('reports.sales.headline_labels.cogs'), value: payload.headline.cogs },
                    { label: t('reports.sales.headline_labels.gross_profit'), value: payload.headline.gross_profit },
                    { label: t('reports.sales.headline_labels.operating_expenses'), value: payload.headline.operating_expenses },
                    { label: t('reports.sales.headline_labels.net_profit'), value: payload.headline.net_profit },
                    { label: t('reports.sales.headline_labels.order_count'), value: payload.headline.order_count },
                    { label: t('reports.sales.headline_labels.average_ticket'), value: payload.headline.avg_ticket },
                ]"
            />

            <!-- v2 charts: lead with the visuals, exact-figure tables follow below -->
            <ReportChart
                type="bar"
                :title="t('reports.sales.charts.profit_overview')"
                :series="pnlChart.series"
                :categories="pnlChart.categories"
                :height="320"
                currency
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <SalesHeatmap
                :title="t('reports.sales.by_hour')"
                :subtitle="t('reports.sales.by_hour_sub')"
                :cells="payload.by_hour_weekday ?? []"
                :empty-text="t('reports.shared.no_data')"
            />

            <ReportChart
                type="bar"
                :title="t('reports.sales.by_weekday')"
                :series="weekdayChart.series"
                :categories="weekdayChart.categories"
                currency
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    type="donut"
                    :title="t('reports.sales.charts.payment_mix')"
                    :series="paymentChart.series"
                    :labels="paymentChart.labels"
                    currency
                    :empty-text="t('reports.shared.no_data')"
                />
                <ReportChart
                    type="donut"
                    :title="t('reports.sales.charts.order_type_mix')"
                    :series="orderTypeChart.series"
                    :labels="orderTypeChart.labels"
                    currency
                    :empty-text="t('reports.shared.no_data')"
                />
            </div>

            <ReportChart
                v-if="branchChart.categories.length > 1"
                type="bar"
                :title="t('reports.shared.by_branch')"
                :series="branchChart.series"
                :categories="branchChart.categories"
                :height="Math.max(220, branchChart.categories.length * 44)"
                currency
                horizontal
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <div v-if="payload.by_branch && payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.branch_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_hour && payload.by_hour.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_hour') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Hour</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_hour" :key="row.hour" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 tabular-nums">{{ String(row.hour).padStart(2, '0') }}:00</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_weekday && payload.by_weekday.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_weekday') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Day</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_weekday" :key="row.weekday" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2">{{ weekdayLabels[row.weekday] ?? row.weekday }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_payment_method && payload.by_payment_method.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_payment_method') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Method</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_payment_method" :key="row.method" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2">{{ methodLabel(row.method) }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.amount }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- P-F9 — Offers: discount applications carrying an offer_id,
                 grouped per offer (rename-safe sale-time names). -->
            <div v-if="payload.by_offer && payload.by_offer.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_offer') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.sales.offer') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.offer_amount') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.offer_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_offer" :key="row.offer_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.amount }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_order_type && payload.by_order_type.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_order_type') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Type</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_order_type" :key="row.type" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 capitalize">{{ orderTypeLabel(row.type) }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

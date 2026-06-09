<script setup lang="ts">
/** Customer Report — blueprint §5.11.8. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchCustomerReport, type CustomerReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<CustomerReportPayload>(fetchCustomerReport);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];

// ---- Chart series (all derived from the live payload) ----

const topCustomersChart = computed(() => {
    const rows = payload.value?.top_customers ?? [];
    return {
        categories: rows.map((r) => r.name),
        series: [{ name: t('reports.shared.value'), data: rows.map((r) => num(r.total_spend)) }] as ApexSeries,
    };
});

const cohortChart = computed(() => {
    const c = payload.value?.cohort;
    return {
        labels: [t('reports.customers.new_count'), t('reports.customers.returning_count')],
        series: [c?.new_count ?? 0, c?.returning_count ?? 0],
    };
});

const loyaltyChart = computed(() => {
    const l = payload.value?.loyalty;
    return {
        categories: [
            t('reports.customers.points_issued'),
            t('reports.customers.points_redeemed'),
            t('reports.customers.net_change'),
        ],
        series: [{
            name: t('reports.customers.loyalty'),
            data: [l?.points_issued ?? 0, l?.points_redeemed ?? 0, l?.net_change ?? 0],
        }] as ApexSeries,
    };
});
</script>

<template>
    <ReportShell :title="t('reports.customers.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.customers.new_count'), value: payload.cohort.new_count },
                    { label: t('reports.customers.returning_count'), value: payload.cohort.returning_count },
                    { label: t('reports.customers.points_issued'), value: payload.loyalty.points_issued },
                    { label: t('reports.customers.points_redeemed'), value: payload.loyalty.points_redeemed },
                    { label: t('reports.customers.net_change'), value: payload.loyalty.net_change },
                    { label: t('reports.customers.outstanding_liability'), value: payload.loyalty.outstanding_liability },
                ]"
            />

            <!-- v2 charts: lead with the visuals, exact-figure tables follow below -->
            <ReportChart
                v-if="payload.top_customers && payload.top_customers.length"
                type="bar"
                :title="t('reports.customers.top_customers')"
                :series="topCustomersChart.series"
                :categories="topCustomersChart.categories"
                :height="Math.max(220, payload.top_customers.length * 40)"
                currency
                horizontal
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    v-if="payload.cohort"
                    type="donut"
                    :title="t('reports.customers.cohort')"
                    :series="cohortChart.series"
                    :labels="cohortChart.labels"
                    :empty-text="t('reports.shared.no_data')"
                />
                <ReportChart
                    v-if="payload.loyalty"
                    type="bar"
                    :title="t('reports.customers.loyalty')"
                    :series="loyaltyChart.series"
                    :categories="loyaltyChart.categories"
                    hide-legend
                    :empty-text="t('reports.shared.no_data')"
                />
            </div>

            <div v-if="payload.top_customers && payload.top_customers.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.customers.top_customers') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Customer</th>
                            <th class="px-5 py-2 text-start">Phone</th>
                            <th class="px-5 py-2 text-end">Total spend</th>
                            <th class="px-5 py-2 text-end">Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.top_customers" :key="row.customer_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.name }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ row.phone ?? '—' }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.total_spend }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
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

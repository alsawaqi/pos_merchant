<script setup lang="ts">
/** Staff Activity Report — blueprint §5.11.10. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchStaffActivityReport, type StaffActivityReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<StaffActivityReportPayload>(fetchStaffActivityReport);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];

// ---- Chart series (all derived from the live payload) ----

// Revenue by staff — sorted DESC so the ranking reads top-to-bottom.
const revenueChart = computed(() => {
    const rows = [...(payload.value?.rows ?? [])].sort((a, b) => num(b.revenue) - num(a.revenue));
    return {
        categories: rows.map((r) => r.staff_name),
        series: [{ name: t('reports.staff_activity.columns.revenue'), data: rows.map((r) => num(r.revenue)) }] as ApexSeries,
    };
});

const ordersChart = computed(() => {
    const rows = [...(payload.value?.rows ?? [])].sort((a, b) => num(b.revenue) - num(a.revenue));
    return {
        categories: rows.map((r) => r.staff_name),
        series: [{ name: t('reports.staff_activity.columns.orders_paid'), data: rows.map((r) => r.orders_paid) }] as ApexSeries,
    };
});
</script>

<template>
    <ReportShell :title="t('reports.staff_activity.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload && payload.rows.length" class="space-y-6">
            <!-- v2 charts: lead with the visuals, exact-figure table follows below -->
            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    v-if="payload.rows.length"
                    type="bar"
                    :title="t('reports.staff_activity.columns.revenue')"
                    :series="revenueChart.series"
                    :categories="revenueChart.categories"
                    :height="Math.max(220, payload.rows.length * 40)"
                    currency
                    horizontal
                    distributed
                    hide-legend
                    :empty-text="t('reports.shared.no_data')"
                />
                <ReportChart
                    v-if="payload.rows.length"
                    type="bar"
                    :title="t('reports.staff_activity.columns.orders_paid')"
                    :series="ordersChart.series"
                    :categories="ordersChart.categories"
                    distributed
                    hide-legend
                    :empty-text="t('reports.shared.no_data')"
                />
            </div>

            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2 text-start">{{ t('reports.staff_activity.columns.staff') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.orders_paid') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.revenue') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.avg_ticket') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.voids') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.discounts') }}</th>
                        <th class="px-5 py-2 text-end">{{ t('reports.staff_activity.columns.hours_logged') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in payload.rows" :key="r.staff_id" class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-2 font-medium text-slate-900">{{ r.staff_name }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.orders_paid }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.revenue }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.avg_ticket }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.voids }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.discounts_applied }}</td>
                        <td class="px-5 py-2 text-end tabular-nums">{{ r.hours_logged }}</td>
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

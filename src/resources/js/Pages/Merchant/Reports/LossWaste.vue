<script setup lang="ts">
/** Loss / Waste Report — blueprint §5.11.5. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchLossWasteReport, type LossWasteReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<LossWasteReportPayload>(fetchLossWasteReport);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// ---- Chart series (all derived from the live payload) ----

const reasonChart = computed(() => {
    const rows = payload.value?.by_reason ?? [];
    return {
        labels: rows.map((r) => r.reason),
        series: rows.map((r) => num(r.value)),
    };
});

const branchChart = computed(() => {
    const rows = payload.value?.by_branch ?? [];
    return {
        categories: rows.map((r) => r.branch_name),
        series: [{ name: t('reports.shared.value'), data: rows.map((r) => num(r.value)) }] as ApexSeries,
    };
});

const topWastedChart = computed(() => {
    const rows = payload.value?.top_wasted ?? [];
    return {
        categories: rows.map((r) => r.ingredient_name),
        series: [{ name: t('reports.shared.value'), data: rows.map((r) => num(r.value)) }] as ApexSeries,
    };
});

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell :title="t('reports.loss_waste.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.loss_waste.headline_labels.total_value'), value: payload.headline.total_value },
                    { label: t('reports.loss_waste.headline_labels.total_qty'), value: payload.headline.total_qty },
                    { label: t('reports.loss_waste.headline_labels.event_count'), value: payload.headline.event_count },
                ]"
            />

            <!-- v2 charts: lead with the visuals, exact-figure tables follow below -->
            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    v-if="reasonChart.labels.length"
                    type="donut"
                    :title="t('reports.loss_waste.by_reason')"
                    :series="reasonChart.series"
                    :labels="reasonChart.labels"
                    currency
                    :empty-text="t('reports.shared.no_data')"
                />
                <ReportChart
                    v-if="branchChart.categories.length > 1"
                    type="bar"
                    :title="t('reports.shared.by_branch')"
                    :series="branchChart.series"
                    :categories="branchChart.categories"
                    currency
                    distributed
                    hide-legend
                    :empty-text="t('reports.shared.no_data')"
                />
            </div>

            <ReportChart
                v-if="topWastedChart.categories.length"
                type="bar"
                :title="t('reports.loss_waste.top_wasted')"
                :series="topWastedChart.series"
                :categories="topWastedChart.categories"
                :height="Math.max(220, topWastedChart.categories.length * 40)"
                currency
                horizontal
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-if="payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.event_count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_branch" :key="r.branch_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ r.branch_name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.value }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.event_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="payload.by_reason.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.loss_waste.by_reason') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">Reason</th><th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.event_count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_reason" :key="r.reason" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 capitalize">{{ r.reason }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.value }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.event_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <section v-if="payload.top_wasted.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.loss_waste.top_wasted') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Ingredient</th><th class="px-5 py-2 text-end">{{ t('reports.shared.qty') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.top_wasted" :key="r.ingredient_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.ingredient_name }} <span class="text-xs text-slate-500">({{ r.unit }})</span></td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.total_qty }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.value }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div v-if="payload._phase?.shortfall_stub" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ payload._phase.shortfall_stub }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

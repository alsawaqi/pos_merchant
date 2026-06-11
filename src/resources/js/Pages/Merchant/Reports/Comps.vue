<script setup lang="ts">
/** Phase B — Comp Report (Additions §1.2: manager write-offs). */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchCompReport, type CompReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<CompReportPayload>(fetchCompReport);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

const reasonChart = computed(() => {
    const rows = payload.value?.by_reason ?? [];
    return {
        labels: rows.map((r) => r.name),
        series: rows.map((r) => num(r.value)),
    };
});

const staffChart = computed(() => {
    const rows = payload.value?.by_staff ?? [];
    return {
        categories: rows.map((r) => r.staff_name),
        series: [{ name: t('reports.comps.headline_labels.total_value'), data: rows.map((r) => num(r.value)) }] as ApexSeries,
    };
});

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell export-key="comps" :title="t('reports.comps.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <!-- P-F5: gifts are their own bucket so the reason-coded comp
                 analysis below stays manager-comps-only. -->
            <HeadlineGrid
                :items="[
                    { label: t('reports.comps.headline_labels.total_value'), value: payload.headline.total_value },
                    { label: t('reports.comps.headline_labels.comp_count'), value: payload.headline.comp_count },
                    { label: t('reports.comps.headline_labels.comped_orders'), value: payload.headline.comped_order_count },
                    { label: t('reports.comps.headline_labels.gift_value'), value: payload.gifts?.total_value ?? '0.000' },
                    { label: t('reports.comps.headline_labels.gift_count'), value: payload.gifts?.gift_count ?? 0 },
                    { label: t('reports.comps.headline_labels.gifted_orders'), value: payload.gifts?.gifted_order_count ?? 0 },
                ]"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    v-if="reasonChart.labels.length"
                    type="donut"
                    :title="t('reports.comps.by_reason')"
                    :series="reasonChart.series"
                    :labels="reasonChart.labels"
                    currency
                    :empty-text="t('reports.shared.no_data')"
                />
                <ReportChart
                    v-if="staffChart.categories.length"
                    type="bar"
                    :title="t('reports.comps.by_staff')"
                    :series="staffChart.series"
                    :categories="staffChart.categories"
                    currency
                    distributed
                    hide-legend
                    :empty-text="t('reports.shared.no_data')"
                />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-if="payload.by_reason.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.comps.by_reason') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">{{ t('reports.comps.reason') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th><th class="px-5 py-2 text-end">{{ t('reports.comps.count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_reason" :key="r.code" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ r.name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.value }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.comp_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th><th class="px-5 py-2 text-end">{{ t('reports.comps.count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_branch" :key="r.branch_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ r.branch_name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.value }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.comp_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <section v-if="payload.recent.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.comps.recent') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.comps.applied_at') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.comps.reason') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.comps.scope') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.shared.value') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.comps.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.recent" :key="r.id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 text-slate-600">{{ r.applied_at ? new Date(r.applied_at).toLocaleString() : '—' }}</td>
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.reason }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="r.scope === 'order' ? 'bg-violet-50 text-violet-700' : 'bg-slate-100 text-slate-600'">
                                    {{ r.scope === 'order' ? t('reports.comps.whole_order') : t('reports.comps.single_line') }}
                                </span>
                            </td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.amount }}</td>
                            <td class="px-5 py-2 text-xs text-slate-500">{{ r.note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

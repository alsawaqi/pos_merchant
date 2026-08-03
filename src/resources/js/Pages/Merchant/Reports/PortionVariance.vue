<script setup lang="ts">
/**
 * Portion Variance Report — where the food actually went, in money.
 *
 * The honest decomposition per ingredient: theoretical recipe usage vs
 * recorded (explained) waste vs day-end count variance (the unexplained
 * signal: over-portioning / unrecorded waste / theft) vs manual
 * adjustments — every bucket in qty AND frozen-at-event cost.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchPortionVarianceReport, type PortionVarianceReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<PortionVarianceReportPayload>(fetchPortionVarianceReport);

/** Report figures arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

const headlineTiles = computed(() => {
    const h = payload.value?.headline;
    if (!h) return [];
    return [
        { key: 'theoretical_cost', value: h.theoretical_cost, money: true, tone: 'text-slate-900' },
        { key: 'waste_cost', value: h.waste_cost, money: true, tone: 'text-amber-700' },
        { key: 'count_shortfall_cost', value: h.count_shortfall_cost, money: true, tone: 'text-rose-700' },
        { key: 'uncounted', value: String(h.uncounted_ingredients), money: false, tone: 'text-slate-900' },
    ];
});

// Top unexplained shortfalls: most negative count-variance cost first.
const shortfallChart = computed(() => {
    const rows = [...(payload.value?.rows ?? [])]
        .filter((r) => num(r.count_variance_cost) < 0)
        .sort((a, b) => num(a.count_variance_cost) - num(b.count_variance_cost))
        .slice(0, 10);
    return {
        categories: rows.map((r) => r.ingredient_name),
        series: [
            {
                name: t('reports.portion_variance.headline.count_shortfall_cost'),
                data: rows.map((r) => Math.abs(num(r.count_variance_cost))),
            },
        ] as ApexSeries,
    };
});

function varianceTone(v: string | number | null | undefined): string {
    const n = num(v);
    if (n < 0) return 'font-semibold text-rose-600';
    if (n > 0) return 'font-semibold text-amber-600';
    return '';
}

function day(v: string | null): string {
    return v ? String(v).slice(0, 10) : '';
}

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell export-key="portion-variance" :title="t('reports.portion_variance.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-4">
            <p class="text-sm text-slate-500">{{ t('reports.portion_variance.subtitle') }}</p>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="tile in headlineTiles" :key="tile.key" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-xs uppercase tracking-wide text-slate-500">
                        {{ t(`reports.portion_variance.headline.${tile.key}`) }}
                    </div>
                    <div class="mt-1 text-xl font-bold tabular-nums" :class="tile.tone">
                        {{ tile.value }}<span v-if="tile.money" class="ms-1 text-xs font-medium text-slate-400">OMR</span>
                    </div>
                </div>
            </div>

            <ReportChart
                v-if="shortfallChart.categories.length"
                type="bar"
                :title="t('reports.portion_variance.chart_title')"
                :series="shortfallChart.series"
                :categories="shortfallChart.categories"
                :height="Math.max(220, shortfallChart.categories.length * 40)"
                horizontal
                distributed
                hide-legend
                currency
                :empty-text="t('reports.shared.no_data')"
            />

            <div v-if="payload.rows.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('reports.portion_variance.columns.ingredient') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.portion_variance.columns.unit') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.theoretical_qty') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.theoretical_cost') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.production_cost') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.waste_qty') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.waste_cost') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.count_variance_qty') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.count_variance_cost') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.adjustment_cost') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.variance_pct') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.portion_variance.columns.last_counted') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.rows" :key="r.ingredient_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-2 font-medium text-slate-900">{{ r.ingredient_name }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ r.unit }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ r.theoretical_qty }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ r.theoretical_cost }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ r.production_cost }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ r.waste_qty }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-amber-700">{{ r.waste_cost }}</td>
                            <td class="px-4 py-2 text-end tabular-nums" :class="varianceTone(r.count_variance_qty)">{{ r.count_variance_qty }}</td>
                            <td class="px-4 py-2 text-end tabular-nums" :class="varianceTone(r.count_variance_cost)">{{ r.count_variance_cost }}</td>
                            <td class="px-4 py-2 text-end tabular-nums" :class="varianceTone(r.adjustment_cost)">{{ r.adjustment_cost }}</td>
                            <td class="px-4 py-2 text-end tabular-nums" :class="varianceTone(r.variance_pct)">
                                {{ r.variance_pct !== null ? `${r.variance_pct}%` : '—' }}
                            </td>
                            <td class="px-4 py-2 text-end text-slate-600">
                                <span
                                    v-if="r.last_counted_at === null"
                                    class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500"
                                >{{ t('reports.portion_variance.never_counted') }}</span>
                                <span v-else class="tabular-nums">{{ day(r.last_counted_at) }}</span>
                            </td>
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

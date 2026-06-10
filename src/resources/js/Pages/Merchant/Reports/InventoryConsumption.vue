<script setup lang="ts">
/** Inventory Consumption Report — blueprint §5.11.3. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchInventoryConsumptionReport, type InventoryConsumptionReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<InventoryConsumptionReportPayload>(fetchInventoryConsumptionReport);

/** Report quantities arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// Top consumed ingredients — sort by consumed desc, take top 15.
const consumedChart = computed(() => {
    const rows = [...(payload.value?.rows ?? [])]
        .sort((a, b) => num(b.consumed) - num(a.consumed))
        .slice(0, 15);
    return {
        categories: rows.map((r) => r.ingredient_name),
        series: [{ name: t('reports.inventory_consumption.columns.consumed'), data: rows.map((r) => num(r.consumed)) }] as ApexSeries,
    };
});

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell export-key="inventory-consumption" :title="t('reports.inventory_consumption.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-4">
            <ReportChart
                v-if="payload.rows.length"
                type="bar"
                :title="t('reports.inventory_consumption.columns.consumed')"
                :series="consumedChart.series"
                :categories="consumedChart.categories"
                :height="Math.max(220, consumedChart.categories.length * 40)"
                horizontal
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <div v-if="payload.rows.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.inventory_consumption.columns.ingredient') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.inventory_consumption.columns.unit') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.consumed') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.balance') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.per_day') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.days_of_stock') }}</th>
                            <!-- Phase A (Additions §2.11) — day-end count columns. -->
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.counted') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.inventory_consumption.columns.count_variance') }}</th>
                            <th class="px-5 py-2 text-center">{{ t('reports.inventory_consumption.columns.below_min') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.rows" :key="r.ingredient_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.ingredient_name }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ r.unit }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.consumed }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.current_balance }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.consumption_per_day }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.days_of_stock ?? '—' }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.counted_units ?? '—' }}</td>
                            <td
                                class="px-5 py-2 text-end tabular-nums"
                                :class="num(r.variance_units) < 0 ? 'font-semibold text-rose-600' : num(r.variance_units) > 0 ? 'font-semibold text-amber-600' : ''"
                            >{{ r.variance_units ?? '—' }}</td>
                            <td class="px-5 py-2 text-center">
                                <span
                                    v-if="r.below_min_threshold"
                                    class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800"
                                >LOW</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload._phase?.anomaly_stub" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ payload._phase.anomaly_stub }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

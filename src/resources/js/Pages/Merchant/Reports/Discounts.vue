<script setup lang="ts">
/** Discount Report — blueprint §5.11.7. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchDiscountReport, type DiscountReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<DiscountReportPayload>(fetchDiscountReport);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// ---- Chart series (all derived from the live payload) ----

const branchChart = computed(() => {
    const rows = payload.value?.by_branch ?? [];
    return {
        categories: rows.map((r) => r.branch_name),
        series: [{ name: t('reports.discounts.headline_labels.total_discount'), data: rows.map((r) => num(r.total_discount)) }] as ApexSeries,
    };
});

const ruleChart = computed(() => {
    const rows = payload.value?.by_rule ?? [];
    return {
        categories: rows.map((r) => r.rule_name),
        series: [{ name: t('reports.discounts.headline_labels.total_discount'), data: rows.map((r) => num(r.total_discount)) }] as ApexSeries,
    };
});

const staffChart = computed(() => {
    const rows = payload.value?.by_staff ?? [];
    return {
        categories: rows.map((r) => r.staff_name),
        series: [{ name: t('reports.discounts.headline_labels.total_discount'), data: rows.map((r) => num(r.total_discount)) }] as ApexSeries,
    };
});

// Local alias so the template stays readable without importing the apex type here.
type ApexSeries = { name: string; data: number[] }[];
</script>

<template>
    <ReportShell export-key="discounts" :title="t('reports.discounts.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.discounts.headline_labels.total_discount'), value: payload.headline.total_discount },
                    { label: t('reports.discounts.headline_labels.gross_sales'), value: payload.headline.gross_sales },
                    { label: t('reports.discounts.headline_labels.discount_pct'), value: `${payload.headline.discount_pct_of_gross}%` },
                    { label: t('reports.discounts.headline_labels.order_count'), value: payload.headline.order_count },
                    { label: t('reports.discounts.headline_labels.discounted_orders'), value: payload.headline.discounted_order_count },
                ]"
            />

            <!-- v2 charts: lead with the visuals, exact-figure tables follow below -->
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

            <ReportChart
                v-if="ruleChart.categories.length"
                type="bar"
                :title="t('reports.discounts.by_rule')"
                :series="ruleChart.series"
                :categories="ruleChart.categories"
                :height="Math.max(220, ruleChart.categories.length * 40)"
                currency
                horizontal
                distributed
                hide-legend
                :empty-text="t('reports.shared.no_data')"
            />

            <ReportChart
                v-if="staffChart.categories.length"
                type="bar"
                :title="t('reports.discounts.by_staff')"
                :series="staffChart.series"
                :categories="staffChart.categories"
                :height="Math.max(220, staffChart.categories.length * 40)"
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
                            <th class="px-5 py-2 text-end">Discount</th>
                            <th class="px-5 py-2 text-end">% of gross</th>
                            <th class="px-5 py-2 text-end">Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.branch_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.total_discount }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.discount_pct }}%</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload._phase && (payload._phase.rule_stub || payload._phase.staff_stub)" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ payload._phase.rule_stub ?? payload._phase.staff_stub }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

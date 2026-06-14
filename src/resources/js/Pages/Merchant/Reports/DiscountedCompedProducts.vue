<script setup lang="ts">
/**
 * Discounted & Comped Products — which exact item was reduced, by what type,
 * how many, and the money taken off. Unifies offers, discounts (incl. loyalty),
 * manager comps and per-item gifts; whole-order applications get their own
 * bucket so the totals reconcile to the Discount + Comp reports.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchDiscountedCompedProductsReport, type DiscountedCompedProductsPayload, type ReductionType } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<DiscountedCompedProductsPayload>(fetchDiscountedCompedProductsReport);

function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

const TYPE_CLASS: Record<ReductionType, string> = {
    offer: 'bg-indigo-50 text-indigo-700',
    discount: 'bg-sky-50 text-sky-700',
    comp: 'bg-amber-50 text-amber-700',
    gift: 'bg-rose-50 text-rose-700',
};
function typeLabel(type: ReductionType): string {
    return t(`reports.discounted_comped_products.types.${type}`);
}

const typeChart = computed(() => {
    const rows = payload.value?.by_type ?? [];
    return {
        labels: rows.map((r) => typeLabel(r.type)),
        series: rows.map((r) => num(r.total_off)),
    };
});
</script>

<template>
    <ReportShell
        export-key="discounted-comped-products"
        :title="t('reports.discounted_comped_products.page_title')"
        v-model="filter"
        :loading="loading"
        :error="error"
        @run="run"
    >
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.discounted_comped_products.headline_labels.total_taken_off'), value: payload.headline.total_taken_off },
                    { label: t('reports.discounted_comped_products.headline_labels.distinct_products'), value: payload.headline.distinct_products },
                    { label: t('reports.discounted_comped_products.headline_labels.applications'), value: payload.headline.application_count },
                    { label: t('reports.discounted_comped_products.headline_labels.product_level'), value: payload.headline.product_level_total },
                    { label: t('reports.discounted_comped_products.headline_labels.whole_order'), value: payload.headline.whole_order_total },
                ]"
            />

            <p class="rounded-lg bg-slate-50 px-4 py-2.5 text-xs text-slate-500">{{ t('reports.discounted_comped_products.types_hint') }}</p>

            <div class="grid gap-6 lg:grid-cols-2">
                <ReportChart
                    v-if="typeChart.labels.length"
                    type="donut"
                    :title="t('reports.discounted_comped_products.by_type')"
                    :series="typeChart.series"
                    :labels="typeChart.labels"
                    currency
                    :empty-text="t('reports.shared.no_data')"
                />

                <section v-if="payload.by_type.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.discounted_comped_products.by_type') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.type') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.times') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_type" :key="r.type" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="TYPE_CLASS[r.type]">{{ typeLabel(r.type) }}</span>
                                </td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.times }}</td>
                                <td class="px-5 py-2 text-end tabular-nums font-medium">{{ r.total_off }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <!-- The star table: exactly which product, by which type. -->
            <section v-if="payload.by_product_and_type.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.discounted_comped_products.by_product_and_type') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.product') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.type') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.units') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.times') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in payload.by_product_and_type" :key="`${r.product_id ?? r.product_name}-${r.type}-${i}`" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="TYPE_CLASS[r.type]">{{ typeLabel(r.type) }}</span>
                            </td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.units }}</td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.times }}</td>
                            <td class="px-5 py-2 text-end tabular-nums font-medium">{{ r.total_off }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-if="payload.by_product.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.discounted_comped_products.by_product') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.product') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.units') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.times') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_product" :key="r.product_id ?? r.product_name" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.units }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.times }}</td>
                                <td class="px-5 py-2 text-end tabular-nums font-medium">{{ r.total_off }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="payload.whole_order.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.discounted_comped_products.whole_order') }}</h2>
                    <p class="px-5 py-2 text-xs text-slate-400">{{ t('reports.discounted_comped_products.whole_order_hint') }}</p>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="r in payload.whole_order" :key="r.type" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="TYPE_CLASS[r.type]">{{ typeLabel(r.type) }}</span>
                                </td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.times }}</td>
                                <td class="px-5 py-2 text-end tabular-nums font-medium">{{ r.total_off }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <section v-if="payload.recent.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.discounted_comped_products.recent') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.when') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.product') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.type') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.discounted_comped_products.cols.name') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.units') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.discounted_comped_products.cols.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in payload.recent" :key="`${r.order_uuid}-${i}`" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 text-slate-600">{{ r.applied_at ? new Date(r.applied_at).toLocaleString() : '—' }}</td>
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name ?? t('reports.discounted_comped_products.whole_order_product') }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="TYPE_CLASS[r.type]">{{ typeLabel(r.type) }}</span>
                            </td>
                            <td class="px-5 py-2 text-slate-600">{{ r.name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.units ?? '—' }}</td>
                            <td class="px-5 py-2 text-end tabular-nums font-medium">{{ r.amount }}</td>
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

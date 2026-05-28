<script setup lang="ts">
/** Product Performance Report — blueprint §5.11.2. */
import { useI18n } from 'vue-i18n';
import { fetchProductPerformanceReport, type ProductPerformanceReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<ProductPerformanceReportPayload>(fetchProductPerformanceReport);
</script>

<template>
    <ReportShell :title="t('reports.product_performance.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="grid gap-6 lg:grid-cols-2">
            <section v-if="payload.top_by_revenue && payload.top_by_revenue.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.product_performance.top_by_revenue') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Product</th><th class="px-5 py-2 text-end">Revenue</th><th class="px-5 py-2 text-end">Qty</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.top_by_revenue" :key="r.product_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.revenue }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.qty }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="payload.top_by_qty && payload.top_by_qty.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.product_performance.top_by_qty') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Product</th><th class="px-5 py-2 text-end">Qty</th><th class="px-5 py-2 text-end">Revenue</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.top_by_qty" :key="r.product_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.qty }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.revenue }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="payload.slow_movers && payload.slow_movers.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.product_performance.slow_movers') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Product</th><th class="px-5 py-2 text-end">Qty</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.slow_movers" :key="r.product_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.qty }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="payload.top_addons && payload.top_addons.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.product_performance.top_addons') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Add-on</th><th class="px-5 py-2 text-end">Attach</th><th class="px-5 py-2 text-end">Revenue</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.top_addons" :key="r.add_on_name_snapshot" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.add_on_name_snapshot }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.attach_count }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.revenue }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <div v-else-if="!loading" class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

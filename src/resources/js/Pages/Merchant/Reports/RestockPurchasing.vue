<script setup lang="ts">
/** Restock / Purchasing Report — blueprint §5.11.6. */
import { useI18n } from 'vue-i18n';
import { fetchRestockPurchasingReport, type RestockPurchasingReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<RestockPurchasingReportPayload>(fetchRestockPurchasingReport);
</script>

<template>
    <ReportShell :title="t('reports.restock_purchasing.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.restock_purchasing.headline_labels.total_cost'), value: payload.headline.total_cost },
                    { label: t('reports.restock_purchasing.headline_labels.total_qty'), value: payload.headline.total_qty },
                    { label: t('reports.restock_purchasing.headline_labels.event_count'), value: payload.headline.event_count },
                ]"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-if="payload.by_supplier.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.restock_purchasing.by_supplier') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">Supplier</th><th class="px-5 py-2 text-end">{{ t('reports.shared.cost') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.event_count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, idx) in payload.by_supplier" :key="r.supplier_id ?? `unassigned-${idx}`" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium" :class="r.supplier_id === null ? 'text-amber-700' : 'text-slate-900'">{{ r.supplier_name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.cost }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.event_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section v-if="payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.cost') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.event_count') }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in payload.by_branch" :key="r.branch_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ r.branch_name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.cost }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ r.event_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <section v-if="payload.top_purchased.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.restock_purchasing.top_purchased') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-2 text-start">Ingredient</th><th class="px-5 py-2 text-end">{{ t('reports.shared.qty') }}</th><th class="px-5 py-2 text-end">{{ t('reports.shared.cost') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.top_purchased" :key="r.ingredient_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.ingredient_name }} <span class="text-xs text-slate-500">({{ r.unit }})</span></td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.total_qty }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.cost }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div v-if="payload._phase?.invoice_stub" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ payload._phase.invoice_stub }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

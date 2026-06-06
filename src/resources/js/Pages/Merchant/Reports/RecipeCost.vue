<script setup lang="ts">
/** Recipe & Cost Report — blueprint §5.11.4. */
import { useI18n } from 'vue-i18n';
import { fetchRecipeCostReport, type RecipeCostReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<RecipeCostReportPayload>(fetchRecipeCostReport);
</script>

<template>
    <ReportShell :title="t('reports.recipe_cost.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-4">
            <div v-if="payload.rows.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.recipe_cost.columns.product') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.recipe_cost.columns.base_price') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.recipe_cost.columns.theoretical_cost') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.recipe_cost.columns.profit_per_unit') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.recipe_cost.columns.margin_pct') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.recipe_cost.columns.lines') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in payload.rows" :key="r.product_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ r.product_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.base_price }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.theoretical_cost }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.profit_per_unit }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ r.margin_pct }}%</td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-500">{{ r.recipe_line_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload._phase?.trend_stub" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ payload._phase.trend_stub }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

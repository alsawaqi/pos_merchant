<script setup lang="ts">
/** Inventory Consumption Report — blueprint §5.11.3. */
import { useI18n } from 'vue-i18n';
import { fetchInventoryConsumptionReport, type InventoryConsumptionReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<InventoryConsumptionReportPayload>(fetchInventoryConsumptionReport);
</script>

<template>
    <ReportShell :title="t('reports.inventory_consumption.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-4">
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

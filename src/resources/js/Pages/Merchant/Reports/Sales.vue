<script setup lang="ts">
/**
 * Sales Report page — blueprint §5.11.1.
 *
 * Renders the headline + 5 breakdown tables. No charts in the MVP.
 */

import { useI18n } from 'vue-i18n';
import { fetchSalesReport, type SalesReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<SalesReportPayload>(fetchSalesReport);

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
</script>

<template>
    <ReportShell
        :title="t('reports.sales.page_title')"
        v-model="filter"
        :loading="loading"
        :error="error"
        @run="run"
    >
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.sales.headline_labels.gross_sales'), value: payload.headline.gross_sales },
                    { label: t('reports.sales.headline_labels.net_sales'), value: payload.headline.net_sales },
                    { label: t('reports.sales.headline_labels.discounts'), value: payload.headline.discounts },
                    { label: t('reports.sales.headline_labels.tax'), value: payload.headline.tax },
                    { label: t('reports.sales.headline_labels.refunds'), value: payload.headline.refunds },
                    { label: t('reports.sales.headline_labels.order_count'), value: payload.headline.order_count },
                    { label: t('reports.sales.headline_labels.average_ticket'), value: payload.headline.average_ticket },
                ]"
            />

            <div v-if="payload.by_branch && payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.branch_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_hour && payload.by_hour.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_hour') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Hour</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_hour" :key="row.hour" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 tabular-nums">{{ String(row.hour).padStart(2, '0') }}:00</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_weekday && payload.by_weekday.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_weekday') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Day</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_weekday" :key="row.weekday" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2">{{ weekdayLabels[row.weekday] ?? row.weekday }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_payment_method && payload.by_payment_method.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_payment_method') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Method</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_payment_method" :key="row.payment_method" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 capitalize">{{ row.payment_method }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="payload.by_order_type && payload.by_order_type.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.sales.by_order_type') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Type</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.gross_sales') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.sales.headline_labels.order_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_order_type" :key="row.order_type" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 capitalize">{{ row.order_type.replace('_', ' ') }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.order_count }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

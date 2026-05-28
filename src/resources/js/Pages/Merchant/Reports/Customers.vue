<script setup lang="ts">
/** Customer Report — blueprint §5.11.8. */
import { useI18n } from 'vue-i18n';
import { fetchCustomerReport, type CustomerReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<CustomerReportPayload>(fetchCustomerReport);
</script>

<template>
    <ReportShell :title="t('reports.customers.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.customers.new_count'), value: payload.cohort.new_count },
                    { label: t('reports.customers.returning_count'), value: payload.cohort.returning_count },
                    { label: t('reports.customers.points_issued'), value: payload.loyalty.points_issued },
                    { label: t('reports.customers.points_redeemed'), value: payload.loyalty.points_redeemed },
                    { label: t('reports.customers.net_change'), value: payload.loyalty.net_change },
                    { label: t('reports.customers.outstanding_liability'), value: payload.loyalty.outstanding_liability },
                ]"
            />

            <div v-if="payload.top_customers && payload.top_customers.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.customers.top_customers') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">Customer</th>
                            <th class="px-5 py-2 text-start">Phone</th>
                            <th class="px-5 py-2 text-end">Total spend</th>
                            <th class="px-5 py-2 text-end">Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.top_customers" :key="row.customer_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ row.name }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ row.phone ?? '—' }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.total_spend }}</td>
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

<script setup lang="ts">
/**
 * Payouts / Commission Breakdown Report.
 *
 * Per-merchant commission split: every paid sale divides into the
 * platform fee, the bank fee (card money only), an "other" bucket,
 * and the merchant's net take-home. The page leads with headline
 * tiles (Gross + the prominent merchant net + the two fees), a donut
 * of the four parties, and a by-branch breakdown table.
 *
 * Branch names: the payout payload carries only branch_id, so we
 * resolve names client-side from /api/branches (the same lean
 * source the shared filter bar uses).
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    fetchPayoutBreakdown,
    fetchMyPayouts,
    type PayoutBreakdownPayload,
    type PayoutPartyType,
    type MerchantPayoutRow,
    type MerchantPayoutStatus,
} from '@/lib/api/reports';
import { listBranches, type Branch } from '@/lib/api/branches';
import { ApiError } from '@/lib/api';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<PayoutBreakdownPayload>(fetchPayoutBreakdown);

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// ---- Branch name lookup (payload only carries branch_id) ----

const branchNames = ref<Map<number, string>>(new Map());

// ---- Payout history (independent of the breakdown Run button) ----
//
// These are the actual stateful payout records the platform creates and
// settles. They aren't window-filtered like the breakdown above — we just
// list them all on mount. Read-only.

const payouts = ref<MerchantPayoutRow[]>([]);
const payoutsLoading = ref(false);

onMounted(async () => {
    try {
        const r = await listBranches();
        branchNames.value = new Map(r.data.map((b: Branch) => [b.id, b.name]));
    } catch (err) {
        // Non-fatal: fall back to "Branch #id" labels.
        if (!(err instanceof ApiError)) throw err;
    }

    payoutsLoading.value = true;
    try {
        const r = await fetchMyPayouts();
        payouts.value = r.data;
    } catch (err) {
        // Non-fatal: leave the history empty.
        if (!(err instanceof ApiError)) throw err;
    } finally {
        payoutsLoading.value = false;
    }
});

// ---- Payout history helpers ----

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString();
}

function payoutPeriod(row: MerchantPayoutRow): string {
    return `${formatDate(row.period_from)} – ${formatDate(row.period_to)}`;
}

function paidOnLabel(row: MerchantPayoutRow): string {
    return row.paid_at ? formatDate(row.paid_at) : t('reports.payouts.history.paid_on_none');
}

function payoutStatusClass(status: MerchantPayoutStatus): string {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700';
        case 'pending': return 'bg-amber-100 text-amber-700';
        case 'cancelled': return 'bg-slate-100 text-slate-600';
        default: return 'bg-slate-100 text-slate-600';
    }
}

function branchLabel(id: number): string {
    return branchNames.value.get(id) ?? `${t('reports.shared.branch')} #${id}`;
}

// ---- Localized party labels ----

function partyLabel(party: PayoutPartyType): string {
    return t(`reports.payouts.parties.${party}`);
}

// ---- Donut series (derived from the live payload) ----

const partyChart = computed(() => {
    const rows = payload.value?.parties ?? [];
    return {
        labels: rows.map((r) => partyLabel(r.party_type)),
        series: rows.map((r) => num(r.total)),
    };
});
</script>

<template>
    <ReportShell :title="t('reports.payouts.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.payouts.headline_labels.gross'), value: payload.headline.gross },
                    { label: t('reports.payouts.headline_labels.merchant_net'), value: payload.headline.merchant_net },
                    { label: t('reports.payouts.headline_labels.platform'), value: payload.headline.platform },
                    { label: t('reports.payouts.headline_labels.bank'), value: payload.headline.bank },
                ]"
            />

            <!-- Commission split: where each OMR of gross goes -->
            <ReportChart
                type="donut"
                :title="t('reports.payouts.charts.commission_split')"
                :series="partyChart.series"
                :labels="partyChart.labels"
                currency
                :empty-text="t('reports.shared.no_data')"
            />

            <div v-if="payload.by_branch && payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.headline_labels.gross') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.headline_labels.platform') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.headline_labels.bank') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.headline_labels.merchant_net') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.headline_labels.num_sales') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ branchLabel(row.branch_id) }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.gross }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.platform }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.bank }}</td>
                            <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.merchant_net }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.num_sales }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>

        <!-- Payout history: the actual stateful payouts (read-only) -->
        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ t('reports.payouts.history.section_title') }}</h2>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="payouts.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.payouts.history.columns.period') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.history.columns.net') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.payouts.history.columns.status') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.payouts.history.columns.sales') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.payouts.history.columns.paid_on') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('reports.payouts.history.columns.reference') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payouts" :key="row.uuid" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 text-slate-700 tabular-nums">{{ payoutPeriod(row) }}</td>
                            <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.net_amount }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="payoutStatusClass(row.status)">{{ t(`reports.payouts.history.statuses.${row.status}`) }}</span>
                            </td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ row.sales_count }}</td>
                            <td class="px-5 py-2 text-slate-700 tabular-nums">{{ paidOnLabel(row) }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ row.reference ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="!payoutsLoading" class="p-8 text-center text-sm text-slate-500">{{ t('reports.payouts.history.empty') }}</div>
            </div>
        </section>
    </ReportShell>
</template>

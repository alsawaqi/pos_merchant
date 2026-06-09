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
import { fetchPayoutBreakdown, type PayoutBreakdownPayload, type PayoutPartyType } from '@/lib/api/reports';
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

onMounted(async () => {
    try {
        const r = await listBranches();
        branchNames.value = new Map(r.data.map((b: Branch) => [b.id, b.name]));
    } catch (err) {
        // Non-fatal: fall back to "Branch #id" labels.
        if (!(err instanceof ApiError)) throw err;
    }
});

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
    </ReportShell>
</template>

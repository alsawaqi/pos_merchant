<script setup lang="ts">
/**
 * Round-Up Donation Report — blueprint §5.11.9.
 *
 * Live round-up donations: each paid sale can round up to charity.
 * The page leads with headline tiles (the prominent total raised plus
 * the donation / pending / failed counts), a donut of donations by
 * lifecycle status, and a by-branch breakdown of SUCCESS-only totals.
 *
 * Branch names: the payload carries only branch_id, so we resolve
 * names client-side from /api/branches (the same lean source the
 * sibling Payouts.vue page uses), with a "Branch #id" fallback.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    fetchRoundUpDonationReport,
    type RoundUpDonationReportPayload,
    type RoundUpDonationStatus,
} from '@/lib/api/reports';
import { listBranches, type Branch } from '@/lib/api/branches';
import { ApiError } from '@/lib/api';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import ReportChart from './components/ReportChart.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<RoundUpDonationReportPayload>(fetchRoundUpDonationReport);

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

// ---- Localized status labels ----

function statusLabel(status: RoundUpDonationStatus): string {
    return t(`reports.round_up_donation.statuses.${status}`);
}

// ---- Donut series: donations by lifecycle status (by count) ----

const statusChart = computed(() => {
    const rows = payload.value?.by_status ?? [];
    return {
        labels: rows.map((r) => statusLabel(r.status)),
        series: rows.map((r) => r.count),
    };
});
</script>

<template>
    <ReportShell :title="t('reports.round_up_donation.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.round_up_donation.headline_labels.total_raised'), value: payload.headline.total_raised },
                    { label: t('reports.round_up_donation.headline_labels.donation_count'), value: payload.headline.donation_count },
                    { label: t('reports.round_up_donation.headline_labels.pending'), value: payload.headline.pending_count },
                    { label: t('reports.round_up_donation.headline_labels.failed'), value: payload.headline.failed_count },
                ]"
            />

            <!-- Donations by lifecycle status (success / pending / fail / void) -->
            <ReportChart
                type="donut"
                :title="t('reports.round_up_donation.charts.by_status')"
                :series="statusChart.series"
                :labels="statusChart.labels"
                :empty-text="t('reports.shared.no_data')"
            />

            <div v-if="payload.by_branch && payload.by_branch.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.shared.by_branch') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.round_up_donation.headline_labels.total_raised') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.round_up_donation.headline_labels.donation_count') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ branchLabel(row.branch_id) }}</td>
                            <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.total_raised }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ row.donation_count }}</td>
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

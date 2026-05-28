<script setup lang="ts">
/**
 * Round-Up Donation Report — blueprint §5.11.9.
 *
 * Renders the zeroed payload + Phase 9 stub banner. The shape is
 * preserved so this page won't need restructuring when the real
 * donation data lands.
 */
import { useI18n } from 'vue-i18n';
import { fetchRoundUpDonationReport, type RoundUpDonationReportPayload } from '@/lib/api/reports';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { filter, payload, loading, error, run } = useReportRunner<RoundUpDonationReportPayload>(fetchRoundUpDonationReport);
</script>

<template>
    <ReportShell :title="t('reports.round_up_donation.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ t('reports.round_up_donation.stub_banner') }}
            </div>

            <HeadlineGrid
                :items="[
                    { label: t('reports.round_up_donation.headline_labels.total_raised'), value: payload.headline.total_raised },
                    { label: t('reports.round_up_donation.headline_labels.donation_count'), value: payload.headline.donation_count },
                    { label: t('reports.round_up_donation.headline_labels.opt_in_rate_pct'), value: `${payload.headline.opt_in_rate_pct}%` },
                ]"
            />
        </div>
    </ReportShell>
</template>

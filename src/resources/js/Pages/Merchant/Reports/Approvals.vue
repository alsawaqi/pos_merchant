<script setup lang="ts">
/**
 * LAUNCH-P5 B3 — Approvals report (owner decision 2: the server checks every
 * approval at sync and records who approved what).
 *
 * One row per gated action from pos_approvals: who did it, who approved it,
 * how (offline / online) and the server's verdict. Failed, missing and
 * unverifiable rows stand out (red). Filters: the shared date + branch bar,
 * plus action, approver, the person who did it, and result ("Problems only"
 * = the three red results). Export (reports.export) carries the same
 * filters and every matching row. reports.view gated.
 */
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    fetchApprovalsReport,
    type ApprovalsReportFilter,
    type ApprovalsReportPayload,
    type ApprovalsReportRow,
    type ReportFilter,
} from '@/lib/api/reports';
import { ALL_RESULTS, actionLabelKey, resultBadgeClass } from '@/lib/approvals';
import { TICK_LIST_ACTIONS } from '@/lib/staffPermissions';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();

const extra = reactive<{ action: string; approver_staff_id: number | null; actor_staff_id: number | null; result: string; page: number }>({
    action: '',
    approver_staff_id: null,
    actor_staff_id: null,
    result: '',
    page: 1,
});

const { filter, payload, loading, error, run } = useReportRunner<ApprovalsReportPayload>(
    (base: ReportFilter) => fetchApprovalsReport(withExtras(base)),
);

function withExtras(base: ReportFilter): ApprovalsReportFilter {
    return {
        ...base,
        action: extra.action || null,
        approver_staff_id: extra.approver_staff_id,
        actor_staff_id: extra.actor_staff_id,
        result: (extra.result || null) as ApprovalsReportFilter['result'],
        page: extra.page,
    };
}

/** The filter the export menu uses: the date/branch bar plus these filters. */
const exportFilter = computed<ApprovalsReportFilter>(() => withExtras(filter.value));

function onBarUpdate(value: ReportFilter): void {
    filter.value = value;
}

function rerun(): void {
    extra.page = 1;
    void run();
}

function goToPage(page: number): void {
    extra.page = page;
    void run();
}

function actionName(action: string): string {
    const key = actionLabelKey(action, TICK_LIST_ACTIONS);
    return key ? t(key) : action;
}

function when(row: ApprovalsReportRow): string {
    const iso = row.approved_at ?? row.recorded_at;
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <ReportShell
        export-key="approvals"
        :title="t('reports.approvals.page_title')"
        :model-value="exportFilter"
        :loading="loading"
        :error="error"
        @update:model-value="onBarUpdate"
        @run="rerun"
    >
        <div class="mb-5 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" data-test="approvals-filters">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t('reports.approvals.filters.action') }}
                <select v-model="extra.action" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm normal-case text-slate-700" data-test="filter-action" @change="rerun">
                    <option value="">{{ t('reports.approvals.filters.all') }}</option>
                    <option v-for="a in payload?.options.actions ?? []" :key="a" :value="a">{{ actionName(a) }}</option>
                </select>
            </label>
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t('reports.approvals.filters.approver') }}
                <select v-model="extra.approver_staff_id" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm normal-case text-slate-700" data-test="filter-approver" @change="rerun">
                    <option :value="null">{{ t('reports.approvals.filters.all') }}</option>
                    <option v-for="s in payload?.options.staff ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t('reports.approvals.filters.actor') }}
                <select v-model="extra.actor_staff_id" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm normal-case text-slate-700" data-test="filter-actor" @change="rerun">
                    <option :value="null">{{ t('reports.approvals.filters.all') }}</option>
                    <option v-for="s in payload?.options.staff ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t('reports.approvals.filters.result') }}
                <select v-model="extra.result" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-2 text-sm normal-case text-slate-700" data-test="filter-result" @change="rerun">
                    <option value="">{{ t('reports.approvals.filters.all') }}</option>
                    <option value="problems">{{ t('reports.approvals.filters.problems') }}</option>
                    <option v-for="r in ALL_RESULTS" :key="r" :value="r">{{ t(`reports.approvals.results.${r}`) }}</option>
                </select>
            </label>
        </div>

        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.approvals.headline.total'), value: payload.summary.total },
                    { label: t('reports.approvals.headline.problems'), value: payload.summary.problems },
                    { label: t('reports.approvals.results.verified'), value: payload.summary.verified },
                    { label: t('reports.approvals.results.position_ok'), value: payload.summary.position_ok },
                ]"
            />

            <p v-if="payload.summary.problems > 0" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" data-test="problems-banner">
                {{ t('reports.approvals.problems_hint', { count: payload.summary.problems }) }}
            </p>

            <section v-if="payload.rows.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('reports.approvals.columns.when') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.approvals.columns.action') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.approvals.columns.actor') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.approvals.columns.approver') }}</th>
                            <th class="px-4 py-2 text-center">{{ t('reports.approvals.columns.result') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.approvals.columns.amount') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.approvals.columns.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in payload.rows"
                            :key="row.id"
                            class="border-b border-slate-100 last:border-0"
                            :class="row.problem ? 'bg-rose-50/70' : ''"
                            :data-test="row.problem ? 'approval-problem' : 'approval-row'"
                        >
                            <td class="px-4 py-2 text-slate-600">{{ when(row) }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.branch_name }}</td>
                            <td class="px-4 py-2 font-medium text-slate-900">{{ actionName(row.action) }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.actor_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-700">
                                {{ row.mode === 'position' ? t('reports.approvals.own_position') : (row.approver_name ?? '—') }}
                                <span v-if="row.method" class="ms-1 text-xs text-slate-400">({{ t(`reports.approvals.methods.${row.method}`) }})</span>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="resultBadgeClass(row.result)">
                                    {{ t(`reports.approvals.results.${row.result}`) }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.amount ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-slate-500">{{ row.reason ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div v-else class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                {{ t('reports.shared.no_data') }}
            </div>

            <div v-if="payload.meta && payload.meta.last_page > 1" class="flex items-center justify-end gap-3 text-sm text-slate-600">
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-50" :disabled="payload.meta.current_page <= 1" @click="goToPage(payload.meta.current_page - 1)">
                    {{ t('reports.approvals.previous') }}
                </button>
                <span>{{ t('reports.approvals.page_of', { page: payload.meta.current_page, pages: payload.meta.last_page }) }}</span>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-50" :disabled="payload.meta.current_page >= payload.meta.last_page" @click="goToPage(payload.meta.current_page + 1)">
                    {{ t('reports.approvals.next') }}
                </button>
            </div>
        </div>
    </ReportShell>
</template>

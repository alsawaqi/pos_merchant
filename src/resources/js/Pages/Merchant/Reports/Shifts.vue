<script setup lang="ts">
/**
 * Phase B — Shift Report (Additions §1.2: cash variance per shift).
 *
 * Lists every shift opened in the window with its float / expected /
 * counted cash and the variance (negative = the drawer is SHORT).
 * A manager (orders.cancel) can RE-OPEN a shift closed today to
 * correct an obvious mistake — audited; the next close recomputes.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { fetchShiftReport, type ShiftReportPayload, type ShiftReportRow } from '@/lib/api/reports';
import { reopenShift } from '@/lib/api/shifts';
import { MerchantPermission } from '@/lib/permissions';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { can } = usePermissions();
const canReopen = computed(() => can(MerchantPermission.OrdersCancel));
const { filter, payload, loading, error, run } = useReportRunner<ShiftReportPayload>(fetchShiftReport);

function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

/** A shift can be re-opened only when it was CLOSED today. */
function reopenable(row: ShiftReportRow): boolean {
    if (row.status !== 'closed' || !row.closed_at) return false;
    const closed = new Date(row.closed_at);
    const today = new Date();
    return closed.getFullYear() === today.getFullYear()
        && closed.getMonth() === today.getMonth()
        && closed.getDate() === today.getDate();
}

const reopenBusyUuid = ref<string | null>(null);
const reopenError = ref<string | null>(null);

async function doReopen(row: ShiftReportRow): Promise<void> {
    reopenBusyUuid.value = row.uuid;
    reopenError.value = null;
    try {
        await reopenShift(row.uuid);
        run();
    } catch (e) {
        if (e instanceof ApiError && e.payload && typeof e.payload === 'object' && 'message' in e.payload) {
            reopenError.value = String((e.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            reopenError.value = e instanceof Error ? e.message : 'Failed';
        }
    } finally {
        reopenBusyUuid.value = null;
    }
}
</script>

<template>
    <ReportShell :title="t('reports.shifts.page_title')" v-model="filter" :loading="loading" :error="error" @run="run">
        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.shifts.headline_labels.shift_count'), value: payload.summary.shift_count },
                    { label: t('reports.shifts.headline_labels.closed_count'), value: payload.summary.closed_count },
                    { label: t('reports.shifts.headline_labels.total_variance'), value: payload.summary.total_variance },
                    { label: t('reports.shifts.headline_labels.total_short'), value: payload.summary.total_short },
                ]"
            />

            <div v-if="reopenError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ reopenError }}
            </div>

            <section v-if="payload.shifts.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('reports.shifts.columns.opened') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.shifts.columns.staff') }}</th>
                            <th class="px-4 py-2 text-center">{{ t('reports.shifts.columns.status') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.shifts.columns.opening') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.shifts.columns.expected') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.shifts.columns.counted') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.shifts.columns.variance') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.shifts.columns.cash_collected') }}</th>
                            <th v-if="canReopen" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in payload.shifts" :key="row.uuid" class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-2 text-slate-600">{{ new Date(row.opened_at).toLocaleString() }}</td>
                            <td class="px-4 py-2 font-medium text-slate-900">{{ row.branch_name }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.staff_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase" :class="row.status === 'open' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">
                                    {{ row.status === 'open' ? t('reports.shifts.open') : t('reports.shifts.closed') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.opening_cash }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.expected_cash ?? '—' }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.counted_cash ?? '—' }}</td>
                            <td class="px-4 py-2 text-end tabular-nums" :class="num(row.variance) < 0 ? 'font-semibold text-rose-600' : ''">
                                {{ row.variance ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.cash_collected ?? '—' }}</td>
                            <td v-if="canReopen" class="px-4 py-2 text-end">
                                <button
                                    v-if="reopenable(row)"
                                    type="button"
                                    :disabled="reopenBusyUuid === row.uuid"
                                    class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60"
                                    @click="doReopen(row)"
                                >
                                    {{ reopenBusyUuid === row.uuid ? t('reports.shifts.reopening') : t('reports.shifts.reopen') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div v-else class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                {{ t('reports.shared.no_data') }}
            </div>
        </div>

        <div v-else-if="!loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            {{ t('reports.shared.no_data') }}
        </div>
    </ReportShell>
</template>

<script setup lang="ts">
/**
 * LAUNCH-P5 B4 — Hours report (clock in / clock out).
 *
 * Per person and per business day (Muscat), with totals. A record with no
 * clock-out after 16 hours stands out (amber) and does not count; one still
 * open within 16 hours shows as "At work". A user with "Correct staff hours"
 * (staff.attendance.manage) can fix a time — a reason is required and the
 * change is audited. Times are shown and typed in Muscat time.
 */
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { updateAttendance } from '@/lib/api/attendance';
import {
    fetchHoursReport,
    type HoursReportFilter,
    type HoursReportPayload,
    type HoursReportRow,
    type ReportFilter,
} from '@/lib/api/reports';
import { formatHours, fromInputValue, hoursRowClass, timeOf, toInputValue } from '@/lib/hours';
import { MerchantPermission } from '@/lib/permissions';
import ReportShell from './components/ReportShell.vue';
import HeadlineGrid from './components/HeadlineGrid.vue';
import { useReportRunner } from './components/useReportRunner';

const { t } = useI18n();
const { can } = usePermissions();
const canEdit = computed(() => can(MerchantPermission.StaffAttendanceManage));

const staffId = ref<number | null>(null);

const { filter, payload, loading, error, run } = useReportRunner<HoursReportPayload>(
    (base: ReportFilter) => fetchHoursReport(withStaff(base)),
);

function withStaff(base: ReportFilter): HoursReportFilter {
    return { ...base, staff_id: staffId.value };
}

const exportFilter = computed<HoursReportFilter>(() => withStaff(filter.value));

function onBarUpdate(value: ReportFilter): void {
    filter.value = value;
}

// ---- Edit a record -------------------------------------------------
const editTarget = ref<HoursReportRow | null>(null);
const editForm = reactive<{ clock_in: string; clock_out: string; still_at_work: boolean; reason: string }>({
    clock_in: '',
    clock_out: '',
    still_at_work: false,
    reason: '',
});
const editBusy = ref(false);
const editError = ref<string | null>(null);

function openEdit(row: HoursReportRow): void {
    editTarget.value = row;
    editForm.clock_in = toInputValue(row.clock_in_local);
    editForm.clock_out = toInputValue(row.clock_out_local);
    editForm.still_at_work = false;
    editForm.reason = '';
    editError.value = null;
}

const canSaveEdit = computed(() => editForm.reason.trim().length >= 3 && editForm.clock_in !== '' && !editBusy.value);

async function saveEdit(): Promise<void> {
    if (!editTarget.value || !canSaveEdit.value) return;
    editBusy.value = true;
    editError.value = null;
    try {
        await updateAttendance(editTarget.value.uuid, {
            clock_in_at: fromInputValue(editForm.clock_in) ?? '',
            clock_out_at: editForm.still_at_work ? null : fromInputValue(editForm.clock_out),
            reason: editForm.reason.trim(),
        });
        editTarget.value = null;
        void run();
    } catch (e) {
        if (e instanceof ApiError) {
            editError.value = e.firstValidationMessage() ?? t('reports.hours.edit.failed');
        } else {
            editError.value = t('reports.hours.edit.failed');
        }
    } finally {
        editBusy.value = false;
    }
}
</script>

<template>
    <ReportShell
        export-key="hours"
        :title="t('reports.hours.page_title')"
        :model-value="exportFilter"
        :loading="loading"
        :error="error"
        @update:model-value="onBarUpdate"
        @run="run"
    >
        <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ t('reports.hours.filters.staff') }}
                <select v-model="staffId" class="mt-1 w-56 rounded-lg border border-slate-200 px-2.5 py-2 text-sm normal-case text-slate-700" data-test="filter-staff" @change="run">
                    <option :value="null">{{ t('reports.hours.filters.everyone') }}</option>
                    <option v-for="s in payload?.options.staff ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>
            <p class="text-xs text-slate-500">{{ t('reports.hours.timezone_note') }}</p>
        </div>

        <div v-if="payload" class="space-y-6">
            <HeadlineGrid
                :items="[
                    { label: t('reports.hours.headline.people'), value: payload.summary.people },
                    { label: t('reports.hours.headline.total_hours'), value: formatHours(payload.summary.total_hours) },
                    { label: t('reports.hours.headline.no_clock_out'), value: payload.summary.no_clock_out },
                    { label: t('reports.hours.headline.at_work'), value: payload.summary.open },
                ]"
            />

            <p v-if="payload.summary.no_clock_out > 0" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-test="no-clock-out-banner">
                {{ t('reports.hours.no_clock_out_hint', { count: payload.summary.no_clock_out }) }}
            </p>

            <section v-if="payload.people.length" class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.hours.per_person') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('reports.hours.columns.person') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.hours.columns.days') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.hours.columns.records') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.hours.columns.hours') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('reports.hours.columns.no_clock_out') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in payload.people" :key="p.staff_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 font-medium text-slate-900">{{ p.staff_name }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ p.days }}</td>
                            <td class="px-5 py-2 text-end tabular-nums">{{ p.records }}</td>
                            <td class="px-5 py-2 text-end tabular-nums font-semibold">{{ formatHours(p.hours) }}</td>
                            <td class="px-5 py-2 text-end tabular-nums" :class="p.no_clock_out > 0 ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ p.no_clock_out }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="payload.rows.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('reports.hours.per_day') }}</h2>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('reports.hours.columns.person') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.hours.columns.day') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.shared.branch') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.hours.columns.clock_in') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.hours.columns.clock_out') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('reports.hours.columns.hours') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('reports.hours.columns.notes') }}</th>
                            <th v-if="canEdit" class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in payload.rows"
                            :key="row.uuid"
                            class="border-b border-slate-100 last:border-0"
                            :class="hoursRowClass(row)"
                            :data-test="row.no_clock_out ? 'hours-no-clock-out' : 'hours-row'"
                        >
                            <td class="px-4 py-2 font-medium text-slate-900">{{ row.staff_name }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ row.day }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ row.branch_name }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ timeOf(row.clock_in_local) }}</td>
                            <td class="px-4 py-2 tabular-nums">
                                <span v-if="row.clock_out_local">{{ row.clock_out_local.slice(0, 10) === row.day ? timeOf(row.clock_out_local) : row.clock_out_local }}</span>
                                <span v-else-if="row.no_clock_out" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-800">{{ t('reports.hours.no_clock_out') }}</span>
                                <span v-else class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">{{ t('reports.hours.at_work') }}</span>
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ formatHours(row.hours) }}</td>
                            <td class="px-4 py-2 text-xs text-slate-500">
                                <span v-if="row.edited" :title="row.edit_reason ?? ''">{{ t('reports.hours.edited_by', { name: row.edited_by ?? '—' }) }}: {{ row.edit_reason }}</span>
                            </td>
                            <td v-if="canEdit" class="px-4 py-2 text-end">
                                <button type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" data-test="hours-edit" @click="openEdit(row)">
                                    {{ t('reports.hours.edit.button') }}
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

        <BaseModal v-if="editTarget" size="md" :title="t('reports.hours.edit.title', { name: editTarget.staff_name })" :loading="editBusy" @close="editTarget = null">
            <form id="hours-edit-form" class="space-y-4" @submit.prevent="saveEdit">
                <p class="text-xs text-slate-500">{{ t('reports.hours.timezone_note') }}</p>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('reports.hours.columns.clock_in') }} *</span>
                    <input v-model="editForm.clock_in" type="datetime-local" required data-test="edit-clock-in" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('reports.hours.columns.clock_out') }}</span>
                    <input v-model="editForm.clock_out" type="datetime-local" :disabled="editForm.still_at_work" data-test="edit-clock-out" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="editForm.still_at_work" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    {{ t('reports.hours.edit.still_at_work') }}
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('reports.hours.edit.reason') }} *</span>
                    <textarea v-model="editForm.reason" rows="2" maxlength="255" required data-test="edit-reason" :placeholder="t('reports.hours.edit.reason_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
                <p class="text-xs text-slate-500">{{ t('reports.hours.edit.audited') }}</p>
                <p v-if="editError" class="text-sm text-rose-600">{{ editError }}</p>
            </form>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editTarget = null">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="hours-edit-form" :disabled="!canSaveEdit" data-test="edit-save" class="rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-60">
                        {{ editBusy ? t('common.saving') : t('common.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </ReportShell>
</template>

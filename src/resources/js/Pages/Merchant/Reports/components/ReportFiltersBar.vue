<script setup lang="ts">
/**
 * Shared report filter bar — Phase 7b-6.
 *
 * Every per-report page mounts this above the result area. The
 * v-model contract:
 *
 *   - modelValue: ReportFilter
 *   - update:modelValue fires when the user edits a field
 *   - emits 'run' when the user clicks Run -- the parent page
 *     owns the actual fetch (so the parent can also re-run on
 *     mount, branch change, etc.)
 *
 * Branch list comes from /api/branches (lean shape). Falls back
 * to a single "All branches" option if the call fails.
 */

import { Play, Calendar, Building2 } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listBranches, type Branch } from '@/lib/api/branches';
import { ApiError } from '@/lib/api';
import type { ReportFilter } from '@/lib/api/reports';

const props = defineProps<{ modelValue: ReportFilter; loading?: boolean }>();
const emit = defineEmits<{
    (e: 'update:modelValue', value: ReportFilter): void;
    (e: 'run'): void;
}>();

const { t } = useI18n();

const branches = ref<Branch[]>([]);

onMounted(async () => {
    try {
        const r = await listBranches();
        branches.value = r.data;
    } catch (err) {
        // Non-fatal: branch filter just shows "All branches" only.
        if (!(err instanceof ApiError)) throw err;
    }
});

function update(patch: Partial<ReportFilter>): void {
    emit('update:modelValue', { ...props.modelValue, ...patch });
}

function onBranchChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    if (target.value === '') {
        update({ branch_ids: null });
    } else {
        update({ branch_ids: [Number(target.value)] });
    }
}

const selectedBranchId = (() => {
    if (!props.modelValue.branch_ids || props.modelValue.branch_ids.length === 0) return '';
    return String(props.modelValue.branch_ids[0]);
})();
</script>

<template>
    <div class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
            <span class="inline-flex items-center gap-1.5 text-slate-500">
                <Calendar class="size-3.5" />
                {{ t('reports.filters.date_from') }}
            </span>
            <input
                type="date"
                :value="modelValue.date_from"
                class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                @input="update({ date_from: ($event.target as HTMLInputElement).value })"
            />
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
            <span class="inline-flex items-center gap-1.5 text-slate-500">
                <Calendar class="size-3.5" />
                {{ t('reports.filters.date_to') }}
            </span>
            <input
                type="date"
                :value="modelValue.date_to"
                class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                @input="update({ date_to: ($event.target as HTMLInputElement).value })"
            />
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
            <span class="inline-flex items-center gap-1.5 text-slate-500">
                <Building2 class="size-3.5" />
                {{ t('reports.filters.branch_label') }}
            </span>
            <select
                :value="selectedBranchId"
                class="w-48 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                @change="onBranchChange"
            >
                <option value="">{{ t('reports.filters.branch_all') }}</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
        </label>

        <button
            type="button"
            class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
            :disabled="loading"
            @click="emit('run')"
        >
            <Play class="size-4" />
            {{ loading ? t('reports.filters.running') : t('reports.filters.run') }}
        </button>
    </div>
</template>

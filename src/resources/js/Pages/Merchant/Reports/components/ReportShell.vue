<script setup lang="ts">
/**
 * ReportShell — Phase 7b-6.
 *
 * Wraps every per-report page in MerchantLayout + a header + the
 * filter bar + a back link to /reports. The page body comes in via
 * the default slot and renders the report-specific tables.
 *
 * The slot receives nothing -- the parent page already has the
 * payload + loading state via useReportRunner.
 *
 * Phase D6: pages that are exportable pass `export-key` (the server's
 * reports/{report}/export key); reports.export holders then get a
 * CSV / Excel / PDF download menu next to the title, reusing the
 * page's live filter.
 */

import { ArrowLeft, ChevronDown, Download } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportFiltersBar from './ReportFiltersBar.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { downloadReportExport } from '@/lib/api/reports';
import type { ReportExportFormat, ReportFilter } from '@/lib/api/reports';

const props = defineProps<{
    title: string;
    modelValue: ReportFilter;
    loading?: boolean;
    error?: string | null;
    /** Server export key (e.g. 'sales'); omit on non-exportable pages. */
    exportKey?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: ReportFilter): void;
    (e: 'run'): void;
}>();

function onUpdate(v: ReportFilter): void {
    emit('update:modelValue', v);
}

const { t } = useI18n();
const { can } = usePermissions();

const exportMenuOpen = ref(false);
const exporting = ref(false);
const exportError = ref<string | null>(null);

const EXPORT_FORMATS: { format: ReportExportFormat; label: string }[] = [
    { format: 'csv', label: 'reports.shared.export_csv' },
    { format: 'xlsx', label: 'reports.shared.export_xlsx' },
    { format: 'pdf', label: 'reports.shared.export_pdf' },
];

async function onExport(format: ReportExportFormat): Promise<void> {
    if (!props.exportKey || exporting.value) {
        return;
    }
    exportMenuOpen.value = false;
    exporting.value = true;
    exportError.value = null;
    try {
        await downloadReportExport(props.exportKey, props.modelValue, format);
    } catch (err) {
        exportError.value =
            err instanceof ApiError
                ? `${t('reports.shared.export_failed')} (HTTP ${err.status})`
                : t('reports.shared.export_failed');
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="max-w-7xl">
            <RouterLink
                to="/reports"
                class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900"
            >
                <ArrowLeft class="size-3.5" />
                Reports
            </RouterLink>

            <header class="mb-4 flex items-start justify-between gap-3">
                <h1 class="text-2xl font-bold text-slate-950">{{ props.title }}</h1>

                <div v-if="props.exportKey && can('reports.export')" class="relative shrink-0">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="exporting || props.loading"
                        @click="exportMenuOpen = !exportMenuOpen"
                    >
                        <Download class="size-3.5" />
                        {{ t('reports.shared.export') }}
                        <ChevronDown class="size-3.5" />
                    </button>

                    <div
                        v-if="exportMenuOpen"
                        class="absolute end-0 z-20 mt-1 w-36 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                    >
                        <button
                            v-for="opt in EXPORT_FORMATS"
                            :key="opt.format"
                            type="button"
                            class="block w-full px-3 py-1.5 text-start text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                            @click="void onExport(opt.format)"
                        >
                            {{ t(opt.label) }}
                        </button>
                    </div>
                </div>
            </header>

            <ReportFiltersBar
                :model-value="props.modelValue"
                :loading="props.loading"
                class="mb-5"
                @update:model-value="onUpdate"
                @run="emit('run')"
            />

            <div v-if="exportError" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ exportError }}
            </div>

            <div v-if="props.error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ props.error }}
            </div>

            <slot />
        </div>
    </MerchantLayout>
</template>

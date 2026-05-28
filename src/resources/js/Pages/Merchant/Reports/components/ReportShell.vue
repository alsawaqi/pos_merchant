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
 */

import { ArrowLeft } from 'lucide-vue-next';
import { RouterLink } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportFiltersBar from './ReportFiltersBar.vue';
import type { ReportFilter } from '@/lib/api/reports';

const props = defineProps<{
    title: string;
    modelValue: ReportFilter;
    loading?: boolean;
    error?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: ReportFilter): void;
    (e: 'run'): void;
}>();

function onUpdate(v: ReportFilter): void {
    emit('update:modelValue', v);
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

            <header class="mb-4 flex flex-col gap-1.5">
                <h1 class="text-2xl font-bold text-slate-950">{{ props.title }}</h1>
            </header>

            <ReportFiltersBar
                :model-value="props.modelValue"
                :loading="props.loading"
                class="mb-5"
                @update:model-value="onUpdate"
                @run="emit('run')"
            />

            <div v-if="props.error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ props.error }}
            </div>

            <slot />
        </div>
    </MerchantLayout>
</template>

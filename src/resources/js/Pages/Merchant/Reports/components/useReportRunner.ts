/**
 * useReportRunner — Phase 7b-6 shared composable.
 *
 * Every per-report page does the same dance:
 *
 *   - hold a reactive ReportFilter (defaulting to "this month")
 *   - hold a payload + loading + error ref
 *   - run the fetch on mount AND when the user clicks Run
 *
 * This composable encapsulates that dance so each report file
 * stays focused on RENDERING the payload, not plumbing.
 */

import { onMounted, ref } from 'vue';
import { ApiError } from '@/lib/api';
import type { ReportFilter } from '@/lib/api/reports';

/**
 * Build a default ReportFilter spanning the first day of the
 * current month through today. Reports default to "current month
 * to date" because that's what merchants check most often.
 */
export function buildDefaultFilter(): ReportFilter {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
        date_from: start.toISOString().slice(0, 10),
        date_to: today.toISOString().slice(0, 10),
        branch_ids: null,
        consolidated: true,
    };
}

export function useReportRunner<T>(fetcher: (filter: ReportFilter) => Promise<{ data: T }>) {
    const filter = ref<ReportFilter>(buildDefaultFilter());
    const payload = ref<T | null>(null);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    async function run(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const r = await fetcher(filter.value);
            payload.value = r.data;
        } catch (err) {
            if (err instanceof ApiError) {
                error.value = `HTTP ${err.status}`;
            } else if (err instanceof Error) {
                error.value = err.message;
            } else {
                error.value = 'Unknown error';
            }
        } finally {
            loading.value = false;
        }
    }

    onMounted(() => { void run(); });

    return { filter, payload, loading, error, run };
}

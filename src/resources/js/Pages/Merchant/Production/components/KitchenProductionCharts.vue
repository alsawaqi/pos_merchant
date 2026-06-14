<script setup lang="ts">
/**
 * KitchenProductionCharts (KP) — the graphical kitchen-production view,
 * shared by the Production page and the branch detail page so both render
 * the exact same visuals from the same payload shape.
 *
 *   - KPI tiles   : batches, pieces, finished / in-progress / cancelled, avg time
 *   - Gantt        : a horizontal timeline (rangeBar) of recent batches,
 *                    grouped per product, coloured by status (start → finish)
 *   - by-product   : pieces produced per product (donut)
 *   - status mix   : batches per status (donut, status colours)
 *   - daily trend  : pieces produced per day (line)
 *   - by-staff     : batches per chef (donut) — only when provided
 *
 * Quantities are PIECES (decimal-3 strings, NOT money) — never currency.
 */

import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import type { KitchenProductionSummary } from '@/lib/api/productions';

const props = defineProps<{
    summary: KitchenProductionSummary;
    /** Small muted line under each chart title, e.g. "Last 30 days". */
    subtitle?: string;
}>();

const { t, locale } = useI18n();
const isAr = computed(() => locale.value === 'ar');

// Status → bar/slice colour, shared by the Gantt and the status donut.
const STATUS_COLORS: Record<string, string> = {
    finished: '#10b981',
    in_progress: '#f59e0b',
    cancelled: '#f43f5e',
};

// Minimum visible bar span (2 min) so instant / zero-length batches still show.
const MIN_SPAN_MS = 2 * 60 * 1000;
// Cap the synthetic end of a still-running batch so one stuck/abandoned
// in-progress batch can't stretch the whole timeline axis to "now".
const MAX_OPEN_MS = 6 * 60 * 60 * 1000;

function productLabel(name: string | null, nameAr: string | null): string {
    return (isAr.value ? nameAr : null) ?? name ?? '—';
}

function trimQty(q: string): string {
    return q.includes('.') ? q.replace(/\.?0+$/, '') : q;
}

const totals = computed(() => props.summary.totals);

function formatDuration(seconds: number): string {
    if (!seconds) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    if (m >= 60) {
        const h = Math.floor(m / 60);
        return `${h}h ${m % 60}m`;
    }
    return `${m}m ${String(s).padStart(2, '0')}s`;
}

const avgDurationLabel = computed(() => formatDuration(totals.value.avg_duration_seconds));

/* ── Gantt timeline ─────────────────────────────────────────────────────── */

const ganttSeries = computed(() => {
    const data = (props.summary.timeline ?? [])
        .filter((b) => b.started_at)
        .map((b) => {
            const start = new Date(b.started_at as string).getTime();
            let end: number;
            if (b.finished_at) {
                end = new Date(b.finished_at).getTime();
            } else if (b.status === 'in_progress') {
                end = Math.min(Date.now(), start + MAX_OPEN_MS);
            } else if (b.duration_seconds) {
                end = start + b.duration_seconds * 1000;
            } else {
                end = start;
            }
            if (end - start < MIN_SPAN_MS) {
                end = start + MIN_SPAN_MS;
            }
            return {
                x: productLabel(b.product_name, b.product_name_ar),
                y: [start, end],
                fillColor: STATUS_COLORS[b.status] ?? '#94a3b8',
            };
        });
    return [{ name: t('production.charts.batch'), data }];
});

// Row count drives the Gantt height (one row per distinct product).
const ganttHeight = computed(() => {
    const rows = new Set(
        (props.summary.timeline ?? []).map((b) => productLabel(b.product_name, b.product_name_ar)),
    );
    const n = Math.max(rows.size, 1);
    return Math.min(Math.max(n * 42 + 90, 220), 560);
});

/* ── Donuts + trend ─────────────────────────────────────────────────────── */

const byProductLabels = computed(() => (props.summary.by_product ?? []).map((p) => productLabel(p.product_name, p.product_name_ar)));
const byProductSeries = computed(() => (props.summary.by_product ?? []).map((p) => Number(p.pieces) || 0));

const statusLabels = computed(() => (props.summary.status_mix ?? []).map((s) => t(`production.status.${s.status}`)));
const statusSeries = computed(() => (props.summary.status_mix ?? []).map((s) => s.count));
const statusColors = computed(() => (props.summary.status_mix ?? []).map((s) => STATUS_COLORS[s.status] ?? '#94a3b8'));

const dayCategories = computed(() => (props.summary.by_day ?? []).map((d) => d.date.slice(5)));
const piecesSeries = computed(() => [
    { name: t('production.charts.pieces'), data: (props.summary.by_day ?? []).map((d) => Number(d.pieces) || 0) },
]);

const hasStaff = computed(() => (props.summary.by_staff?.length ?? 0) > 0);
const staffLabels = computed(() => (props.summary.by_staff ?? []).map((s) => s.staff_name));
const staffSeries = computed(() => (props.summary.by_staff ?? []).map((s) => s.batches));

const emptyText = computed(() => t('production.charts.no_data'));
</script>

<template>
    <div class="space-y-6">
        <!-- KPI tiles -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('production.charts.batches') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-teal-900">{{ totals.batches }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('production.charts.pieces') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-slate-950">{{ trimQty(totals.pieces) }}</div>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ t('production.status.finished') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-emerald-900">{{ totals.finished }}</div>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ t('production.status.in_progress') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-amber-900">{{ totals.in_progress }}</div>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-rose-600">{{ t('production.status.cancelled') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-rose-700">{{ totals.cancelled }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('production.charts.avg_time') }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-slate-950">{{ avgDurationLabel }}</div>
            </div>
        </div>

        <!-- Gantt timeline -->
        <ReportChart
            type="rangeBar"
            :title="t('production.charts.timeline')"
            :subtitle="subtitle"
            :series="ganttSeries"
            :height="ganttHeight"
            :empty-text="emptyText"
        />

        <!-- by-product + status donuts -->
        <div class="grid gap-6 lg:grid-cols-2">
            <ReportChart
                type="donut"
                :title="t('production.charts.by_product')"
                :subtitle="subtitle"
                :series="byProductSeries"
                :labels="byProductLabels"
                :decimals="3"
                :height="300"
                :empty-text="emptyText"
            />
            <ReportChart
                type="donut"
                :title="t('production.charts.status_mix')"
                :subtitle="subtitle"
                :series="statusSeries"
                :labels="statusLabels"
                :colors="statusColors"
                :height="300"
                :empty-text="emptyText"
            />
        </div>

        <!-- daily pieces trend -->
        <ReportChart
            type="line"
            :title="t('production.charts.daily_pieces')"
            :subtitle="subtitle"
            :series="piecesSeries"
            :categories="dayCategories"
            :decimals="3"
            :height="260"
            hide-legend
            :empty-text="emptyText"
        />

        <!-- by-staff donut (Production page only) -->
        <ReportChart
            v-if="hasStaff"
            type="donut"
            :title="t('production.charts.by_staff')"
            :subtitle="subtitle"
            :series="staffSeries"
            :labels="staffLabels"
            :height="300"
            :empty-text="emptyText"
        />
    </div>
</template>

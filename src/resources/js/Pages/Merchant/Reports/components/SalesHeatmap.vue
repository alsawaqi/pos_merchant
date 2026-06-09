<script setup lang="ts">
/**
 * SalesHeatmap — "Sales by Hour" weekly heatmap (ApexCharts heatmap).
 *
 * A matrix with HOURS as rows (00:00 at the top → 23:00 at the bottom)
 * and DAYS OF WEEK as columns (Monday → Sunday). Each cell is the paid
 * gross (OMR) for that hour-of-day on that weekday, colour-shaded from
 * light to dark orange by intensity.
 *
 * Feed it the backend's sparse `cells` list — every breakdown action
 * emits `{ weekday: 0..6 (Sun=0), hour: 0..23, gross: decimal-3 str }`
 * for buckets that have orders. The component zero-fills the rest of the
 * 7×24 grid. Money arrives as decimal-OMR strings (parsed here).
 *
 * Day/hour labels follow the UI locale; cell values stay Latin OMR.
 */

import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VueApexCharts from 'vue3-apexcharts';
import type { ApexOptions } from 'apexcharts';

interface HeatmapCell {
    weekday: number; // 0 = Sunday … 6 = Saturday
    hour: number;    // 0 … 23
    gross: string | number;
    count?: number;
}

const props = withDefaults(
    defineProps<{
        /** Sparse buckets from the report action. */
        cells: HeatmapCell[];
        /** Card header. Omit to render bare (no card chrome). */
        title?: string;
        /** Small muted line under the title. */
        subtitle?: string;
        height?: number;
        emptyText?: string;
    }>(),
    { height: 540 },
);

const { locale } = useI18n();

// Columns ordered Monday → Sunday (backend weekday is Sun=0..Sat=6).
const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

const localeTag = computed(() => (locale.value === 'ar' ? 'ar-OM' : 'en-GB'));
const moneyFmt = computed(() => new Intl.NumberFormat('en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 }));

/** Short localized weekday name for backend index (0=Sun..6=Sat). */
function dayLabel(weekday: number): string {
    // 2023-01-01 is a Sunday; offset by the weekday index.
    const d = new Date(2023, 0, 1 + weekday);
    return new Intl.DateTimeFormat(localeTag.value, { weekday: 'short' }).format(d);
}

function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

// values[hour][weekday] = gross OMR
const grid = computed<number[][]>(() => {
    const g: number[][] = Array.from({ length: 24 }, () => Array.from({ length: 7 }, () => 0));
    for (const c of props.cells ?? []) {
        const row = g[c.hour];
        if (row && c.weekday >= 0 && c.weekday < 7) {
            row[c.weekday] = num(c.gross);
        }
    }
    return g;
});

const maxValue = computed<number>(() => {
    let max = 0;
    for (const row of grid.value) {
        for (const v of row) if (v > max) max = v;
    }
    return max;
});

const isEmpty = computed<boolean>(() => maxValue.value <= 0);

// Series: one per hour. ApexCharts renders series[0] at the BOTTOM, so we
// emit hour 23 first and hour 0 last → 00:00 sits at the top of the grid.
const series = computed(() => {
    const dayLabels = DAY_ORDER.map(dayLabel);
    const out: { name: string; data: { x: string; y: number }[] }[] = [];
    for (let hour = 23; hour >= 0; hour--) {
        const row = grid.value[hour] ?? [];
        out.push({
            name: hourLabel(hour),
            data: DAY_ORDER.map((wd, i) => ({ x: dayLabels[i] ?? '', y: row[wd] ?? 0 })),
        });
    }
    return out;
});

// Light → dark orange buckets, with empty cells a faint slate.
const colorRanges = computed(() => {
    const max = maxValue.value;
    const ranges: { from: number; to: number; color: string; name?: string }[] = [
        { from: 0, to: 0, color: '#f1f5f9', name: '—' },
    ];
    if (max > 0) {
        const shades = ['#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#ea580c'];
        const step = max / shades.length;
        for (let i = 0; i < shades.length; i++) {
            ranges.push({
                from: i === 0 ? 0.0001 : step * i,
                to: i === shades.length - 1 ? max : step * (i + 1),
                color: shades[i] ?? '#ea580c',
            });
        }
    }
    return ranges;
});

const options = computed<ApexOptions>(() => ({
    chart: {
        type: 'heatmap',
        fontFamily: 'inherit',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: true, speed: 300 },
    },
    dataLabels: { enabled: false },
    stroke: { width: 2, colors: ['#ffffff'] },
    legend: { show: false },
    xaxis: {
        type: 'category',
        position: 'top',
        labels: { style: { colors: '#475569', fontSize: '12px', fontWeight: 600 } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: { style: { colors: '#94a3b8', fontSize: '11px' } },
    },
    plotOptions: {
        heatmap: {
            radius: 2,
            enableShades: false,
            colorScale: { ranges: colorRanges.value },
        },
    },
    tooltip: {
        custom: ({ seriesIndex, dataPointIndex, w }) => {
            const hour = w.globals.seriesNames[seriesIndex] as string;
            const day = w.globals.labels[dataPointIndex] as string;
            const val = (w.globals.series[seriesIndex][dataPointIndex] as number) ?? 0;
            return (
                `<div class="px-2.5 py-1.5 text-xs">` +
                `<div class="font-semibold text-slate-700">${day} · ${hour}</div>` +
                `<div class="tabular-nums text-slate-500">${moneyFmt.value.format(val)} OMR</div>` +
                `</div>`
            );
        },
    },
}));
</script>

<template>
    <div v-if="title" class="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-baseline justify-between border-b border-slate-200 px-5 py-3">
            <h2 class="text-sm font-semibold text-slate-700">{{ title }}</h2>
            <span v-if="subtitle" class="text-xs text-slate-400">{{ subtitle }}</span>
        </div>
        <div class="px-2 py-3">
            <div v-if="isEmpty" class="flex items-center justify-center py-12 text-sm text-slate-400">
                {{ emptyText ?? 'No data' }}
            </div>
            <VueApexCharts v-else type="heatmap" :height="height" :options="options" :series="series" />
        </div>
    </div>

    <div v-else>
        <div v-if="isEmpty" class="flex items-center justify-center py-12 text-sm text-slate-400">
            {{ emptyText ?? 'No data' }}
        </div>
        <VueApexCharts v-else type="heatmap" :height="height" :options="options" :series="series" />
    </div>
</template>

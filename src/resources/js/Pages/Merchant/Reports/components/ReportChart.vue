<script setup lang="ts">
/**
 * ReportChart — v2 Step 1 shared chart wrapper (ApexCharts).
 *
 * One reusable component for every report/dashboard graph so the
 * theme (palette, fonts, tooltip, grid) stays uniform across the
 * app. Wraps `vue3-apexcharts` and applies a slate/teal house style.
 *
 * Supported `type`s: 'bar' (vertical or horizontal), 'line', 'area',
 * 'donut'. For bar/line/area pass `series` as ApexAxisChartSeries
 * ([{ name, data:number[] }]) plus `categories` (x labels). For
 * donut pass `series` as number[] plus `labels` (slice names).
 *
 * Money series are decimal-OMR (the report actions emit decimal-3
 * strings — parse before passing). Set `currency` so tooltips/axis
 * append "OMR" and group thousands. Chart numerals stay Latin even
 * in Arabic locale (charts read better that way); date/text labels
 * passed in via `categories`/`labels` are already localized by the
 * caller.
 */

import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { ApexOptions, ApexAxisChartSeries, ApexNonAxisChartSeries } from 'apexcharts';

type ChartType = 'bar' | 'line' | 'area' | 'donut';

const props = withDefaults(
    defineProps<{
        type: ChartType;
        /** Card header. Omit to render the chart bare (no card chrome). */
        title?: string;
        /** Small muted line under the title. */
        subtitle?: string;
        /** bar/line/area: [{ name, data:number[] }]. donut: number[]. */
        series: ApexAxisChartSeries | ApexNonAxisChartSeries | number[];
        /** x-axis category labels for bar/line/area. */
        categories?: (string | number)[];
        /** slice labels for donut. */
        labels?: string[];
        height?: number;
        /** Format values as OMR (thousands group + "OMR" suffix). */
        currency?: boolean;
        /** Horizontal bars — best for "top N" rankings. */
        horizontal?: boolean;
        stacked?: boolean;
        /** Distinct colour per bar (single-series bar charts). */
        distributed?: boolean;
        /** Hide the legend (e.g. single-series bars). */
        hideLegend?: boolean;
        /** Override the default palette. */
        colors?: string[];
        /** Shown when there is no data to plot. */
        emptyText?: string;
    }>(),
    {
        height: 300,
        currency: false,
        horizontal: false,
        stacked: false,
        distributed: false,
        hideLegend: false,
    },
);

// House palette — teal-led, matches the merchant UI accents.
const PALETTE = [
    '#0d9488', '#0ea5e9', '#6366f1', '#f59e0b', '#ec4899',
    '#22c55e', '#ef4444', '#8b5cf6', '#14b8a6', '#eab308',
];

// Latin grouping for chart numerals regardless of UI locale.
const groupFmt = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
const moneyFmt = new Intl.NumberFormat('en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

function fmtValue(n: number): string {
    if (!Number.isFinite(n)) return '0';
    return props.currency ? moneyFmt.format(n) : groupFmt.format(n);
}

/** Compact axis label (e.g. 12.3K, 1.2M) to keep the y-axis readable. */
function fmtAxis(n: number): string {
    if (!Number.isFinite(n)) return '0';
    const abs = Math.abs(n);
    if (abs >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (abs >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
    return props.currency ? n.toFixed(0) : groupFmt.format(n);
}

const isEmpty = computed<boolean>(() => {
    const s = props.series as unknown[];
    if (!Array.isArray(s) || s.length === 0) return true;
    if (props.type === 'donut') {
        return (s as number[]).every((v) => !v);
    }
    // axis series: empty if every series has no non-zero datum
    return (s as ApexAxisChartSeries).every((ser) => {
        const data = (ser?.data ?? []) as number[];
        return data.length === 0 || data.every((v) => !v);
    });
});

const colors = computed(() => props.colors ?? PALETTE);

const options = computed<ApexOptions>(() => {
    const suffix = props.currency ? ' OMR' : '';

    const base: ApexOptions = {
        chart: {
            type: props.type,
            fontFamily: 'inherit',
            toolbar: { show: false },
            zoom: { enabled: false },
            stacked: props.stacked,
            animations: { enabled: true, speed: 400 },
        },
        colors: colors.value,
        dataLabels: { enabled: false },
        legend: {
            show: !props.hideLegend,
            position: 'bottom',
            fontSize: '12px',
            markers: { /* keep defaults */ },
            labels: { colors: '#475569' },
        },
        tooltip: {
            y: { formatter: (v: number) => `${fmtValue(v)}${suffix}` },
        },
        grid: {
            borderColor: '#e2e8f0',
            strokeDashArray: 4,
            padding: { left: 8, right: 8 },
        },
        noData: { text: props.emptyText ?? 'No data' },
    };

    if (props.type === 'donut') {
        return {
            ...base,
            labels: props.labels ?? [],
            stroke: { width: 2, colors: ['#ffffff'] },
            plotOptions: {
                pie: {
                    donut: {
                        size: '62%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total',
                                color: '#64748b',
                                formatter: (w) => {
                                    const sum = (w.globals.seriesTotals as number[]).reduce((a, b) => a + b, 0);
                                    return `${fmtValue(sum)}${suffix}`;
                                },
                            },
                        },
                    },
                },
            },
            tooltip: { y: { formatter: (v: number) => `${fmtValue(v)}${suffix}` } },
        };
    }

    // bar / line / area
    const axisBase: ApexOptions = {
        ...base,
        xaxis: {
            categories: props.categories ?? [],
            labels: { style: { colors: '#64748b', fontSize: '11px' } },
            axisBorder: { color: '#e2e8f0' },
            axisTicks: { color: '#e2e8f0' },
        },
        yaxis: {
            labels: {
                style: { colors: '#94a3b8', fontSize: '11px' },
                formatter: (v: number) => fmtAxis(v),
            },
        },
    };

    if (props.type === 'bar') {
        return {
            ...axisBase,
            plotOptions: {
                bar: {
                    horizontal: props.horizontal,
                    borderRadius: 4,
                    borderRadiusApplication: 'end',
                    columnWidth: '60%',
                    distributed: props.distributed,
                },
            },
            // distributed single-series bars repeat the legend pointlessly
            legend: { ...base.legend, show: !props.hideLegend && !props.distributed },
        };
    }

    // line / area
    return {
        ...axisBase,
        stroke: { curve: 'smooth', width: props.type === 'area' ? 2 : 3 },
        fill:
            props.type === 'area'
                ? { type: 'gradient', gradient: { shadeIntensity: 0.3, opacityFrom: 0.4, opacityTo: 0.05 } }
                : { type: 'solid' },
        markers: { size: 0, hover: { size: 4 } },
    };
});
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
            <VueApexCharts
                v-else
                :type="type"
                :height="height"
                :options="options"
                :series="series"
            />
        </div>
    </div>

    <div v-else>
        <div v-if="isEmpty" class="flex items-center justify-center py-12 text-sm text-slate-400">
            {{ emptyText ?? 'No data' }}
        </div>
        <VueApexCharts
            v-else
            :type="type"
            :height="height"
            :options="options"
            :series="series"
        />
    </div>
</template>

<script setup lang="ts">
/**
 * Merchant Dashboard — Phase 7b-7.
 *
 * Replaces the Sprint 4 placeholder with real widgets:
 *
 *   - Welcome banner (kept)
 *   - Today gross + order count tile, with delta-vs-yesterday chip
 *   - Month-to-date gross + order count tile
 *   - Top product today tile (snapshot name; null when no orders)
 *   - Low-stock ingredient count tile
 *   - Round-up donations today tile + active devices tile (§5.2)
 *   - Payment mix today donut (cash vs card vs split tenders)
 *   - Recent activity feed (last 5 audit events) -- only shown
 *     to roles with audit_log.view, since the user otherwise
 *     wouldn't see event names anywhere in the SPA
 *
 * Permission gating:
 *   - The summary endpoint requires reports.view server-side.
 *   - We don't gate the page itself in the SPA -- a user without
 *     reports.view sees the welcome banner + an empty data area
 *     with a friendly message (the API will 403 silently).
 */

import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Sparkles, Target, TrendingUp, TrendingDown, Minus, Trophy, AlertTriangle, History, CheckCircle2, HeartHandshake, MonitorSmartphone } from 'lucide-vue-next';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import SalesHeatmap from '@/Pages/Merchant/Reports/components/SalesHeatmap.vue';
import SalesComparison from '@/Pages/Merchant/Reports/components/SalesComparison.vue';
import { authState } from '@/stores/auth';
import { fetchDashboardSummary, type DashboardSummaryPayload } from '@/lib/api/dashboard';
import {
    fetchBranchPerformance,
    type BranchPerformanceRow,
    type RecentMiss,
} from '@/lib/api/branchTargets';
import { markMissPopupShown, shouldShowMissPopup } from '@/stores/targets';
import { ApiError } from '@/lib/api';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';

const { t, tm, locale } = useI18n();
const { can } = usePermissions();

const comingSoon = tm('dashboard.coming_soon_list') as string[];

const summary = ref<DashboardSummaryPayload | null>(null);
const loading = ref<boolean>(true);
const error = ref<string | null>(null);
const forbidden = ref<boolean>(false);

const canSeeAudit = computed(() => can(MerchantPermission.AuditLogView));

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    forbidden.value = false;
    try {
        const r = await fetchDashboardSummary();
        summary.value = r.data;
    } catch (err) {
        if (err instanceof ApiError) {
            if (err.status === 403) {
                forbidden.value = true;
            } else {
                error.value = t('dashboard_widgets.load_failed');
            }
        } else {
            error.value = t('dashboard_widgets.load_failed');
        }
    } finally {
        loading.value = false;
    }
}

// ---- P-G8 — Branch Performance (its own endpoint; degrades silently
// so a targets hiccup never blanks the rest of the dashboard) ----
const performance = ref<BranchPerformanceRow[]>([]);
const recentMisses = ref<RecentMiss[]>([]);
const missPopupOpen = ref(false);

async function loadPerformance(): Promise<void> {
    try {
        const r = await fetchBranchPerformance();
        performance.value = r.data;
        recentMisses.value = r.recent_misses;
        // "Popup on portal login when a branch just missed" — once per
        // SPA session (the dashboard is the landing page after login).
        if (r.recent_misses.length > 0 && shouldShowMissPopup()) {
            markMissPopupShown();
            missPopupOpen.value = true;
        }
    } catch {
        performance.value = [];
        recentMisses.value = [];
    }
}

/** "day 2 of 3" / "week 1 of 2" / "month 1 of 1" per the target period. */
function elapsedLabel(row: BranchPerformanceRow): string {
    const key = `dashboard_targets.elapsed_${row.period}`;
    return t(key, { n: row.elapsed_periods, total: row.window_periods });
}

function progressBarClass(row: BranchPerformanceRow): string {
    // Pace-aware colour: green when at/above the pro-rata TIME pace.
    // Computed from the window's actual day span (the server's
    // elapsed_periods is 1-based and counts the in-progress period, which
    // would demand a full period's takings the moment it starts). Each
    // elapsed day earns a half-day credit so day 1 of a 1-day window
    // expects ~50%, not 0% or 100%.
    const dayMs = 86_400_000;
    const start = new Date(`${row.window_start}T00:00:00`).getTime();
    const end = new Date(`${row.window_end}T00:00:00`).getTime();
    const totalDays = Math.max(1, Math.round((end - start) / dayMs) + 1);
    const elapsedDays = Math.min(
        totalDays,
        Math.max(1, Math.floor((Date.now() - start) / dayMs) + 1),
    );
    const pace = ((elapsedDays - 0.5) / totalDays) * 100;
    if (row.progress_pct >= Math.min(100, pace)) return 'bg-emerald-500';
    if (row.progress_pct >= pace * 0.6) return 'bg-amber-500';
    return 'bg-rose-500';
}

onMounted(() => {
    void load();
    void loadPerformance();
});

/**
 * Compute the % delta today vs yesterday. Returns null when
 * yesterday is 0 (would be division by zero / infinite growth).
 */
const todayVsYesterdayPct = computed<number | null>(() => {
    if (!summary.value) return null;
    const today = Number(summary.value.today.gross);
    const yesterday = Number(summary.value.yesterday.gross);
    if (!Number.isFinite(today) || !Number.isFinite(yesterday) || yesterday === 0) return null;
    return Math.round(((today - yesterday) / yesterday) * 100);
});

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

type ApexSeries = { name: string; data: number[] }[];

// ---- Dashboard chart series (derived from the summary payload) ----

const trendChart = computed(() => {
    const pts = summary.value?.sales_trend ?? [];
    return {
        // Short day label (e.g. "02 Jun"); keep Latin for chart axis readability.
        categories: pts.map((p) => {
            const d = new Date(`${p.date}T00:00:00`);
            return Number.isNaN(d.getTime())
                ? p.date
                : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
        }),
        series: [{ name: t('dashboard_widgets.gross'), data: pts.map((p) => num(p.gross)) }] as ApexSeries,
    };
});

const topBranchesChart = computed(() => {
    const rows = summary.value?.top_branches ?? [];
    return {
        categories: rows.map((r) => r.branch_name),
        series: [{ name: t('dashboard_widgets.gross'), data: rows.map((r) => num(r.gross)) }] as ApexSeries,
    };
});

const topCustomersChart = computed(() => {
    const rows = summary.value?.top_customers ?? [];
    return {
        categories: rows.map((r) => r.customer_name || '—'),
        series: [{ name: t('dashboard_widgets.gross'), data: rows.map((r) => num(r.total_spend)) }] as ApexSeries,
    };
});

const topIngredientsChart = computed(() => {
    const rows = summary.value?.top_ingredients ?? [];
    return {
        categories: rows.map((r) => `${r.ingredient_name} (${r.unit})`),
        series: [{ name: t('reports.inventory_consumption.columns.consumed'), data: rows.map((r) => num(r.consumed)) }] as ApexSeries,
    };
});

// Donut variants (chart variety): revenue share by product + by staff.
const topProductsDonut = computed(() => {
    const rows = summary.value?.top_products ?? [];
    return { labels: rows.map((r) => r.product_name), series: rows.map((r) => num(r.revenue)) };
});

const topStaffDonut = computed(() => {
    const rows = summary.value?.top_staff ?? [];
    return { labels: rows.map((r) => r.staff_name), series: rows.map((r) => num(r.revenue)) };
});

const hourCells = computed(() => summary.value?.hour_weekday.cells ?? []);

// Order-type (channel) label via the orders.types.* i18n map.
function orderTypeLabel(type: string): string {
    const key = `orders.types.${type}`;
    const label = t(key);
    return label !== key ? label : (type || '—').replace(/_/g, ' ');
}

// Short localized weekday name for index 0..6 (Sun=0). 2023-01-01 was a Sunday.
function weekdayName(i: number): string {
    return new Date(2023, 0, 1 + i).toLocaleDateString(locale.value === 'ar' ? 'ar' : 'en-GB', { weekday: 'short' });
}

// Channel mix — a polar-area of MTD sales by order type.
const channelMix = computed(() => {
    const rows = summary.value?.order_type_mix ?? [];
    return { labels: rows.map((r) => orderTypeLabel(r.order_type)), series: rows.map((r) => num(r.gross)) };
});

// Busiest days — a radar of weekly sales rhythm (from the hour×weekday matrix).
const busiestDays = computed(() => {
    const totals = Array.from({ length: 7 }, () => 0);
    for (const c of hourCells.value) {
        if (c.weekday >= 0 && c.weekday < 7) totals[c.weekday] += num(c.gross);
    }
    return {
        categories: Array.from({ length: 7 }, (_, i) => weekdayName(i)),
        series: [{ name: t('dashboard_widgets.gross'), data: totals }] as ApexSeries,
    };
});

// Today vs your recent daily average — an animated radial gauge.
const todayVsAvg = computed(() => {
    const trend = summary.value?.sales_trend ?? [];
    const past = trend.slice(0, -1); // exclude today (the last point)
    const sum = past.reduce((a, p) => a + num(p.gross), 0);
    const avg = past.length ? sum / past.length : 0;
    const pct = avg > 0 ? Math.round((num(summary.value?.today.gross) / avg) * 100) : 0;
    return { series: [pct], labels: [t('dashboard_widgets.vs_avg_label')], hasData: avg > 0 };
});

const busiestDaysHasData = computed(() => busiestDays.value.series[0].data.some((v) => v > 0));

// Payment mix today donut (§5.2). Method labels resolve through the
// orders.payment_methods i18n map (P-F5: 'bank_pos' → "Bank POS"),
// falling back to plain capitalisation for an unknown method.
function methodLabel(method: string): string {
    const key = `orders.payment_methods.${method}`;
    const label = t(key);
    return label !== key ? label : method.charAt(0).toUpperCase() + method.slice(1).replace(/_/g, ' ');
}

const paymentMixChart = computed(() => {
    const rows = summary.value?.payment_mix_today ?? [];
    return {
        labels: rows.map((r) => methodLabel(r.method)),
        series: rows.map((r) => num(r.amount)),
    };
});
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Welcome banner -->
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-indigo-950 to-teal-900 px-8 py-8 text-white shadow-2xl shadow-slate-900/20 sm:px-12 sm:py-10">
                <div class="pointer-events-none absolute -end-20 -top-20 size-72 rounded-full bg-teal-500/30 blur-3xl" />
                <div class="pointer-events-none absolute -bottom-24 -start-16 size-80 rounded-full bg-indigo-500/25 blur-3xl" />
                <div class="relative">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-teal-200 backdrop-blur">
                        <Sparkles class="size-3.5" />
                        {{ t('app.tagline') }}
                    </span>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">
                        {{ t('dashboard.title') }}{{ authState.user?.name ? `, ${authState.user.name}` : '' }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/80">{{ t('dashboard.subtitle') }}</p>
                </div>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <!-- P-G8 — Branch Performance: the current evaluation window
                 per branch target ("380 / 600 — day 2 of 3"). -->
            <div v-if="performance.length > 0" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <Target class="size-4 text-teal-600" />
                    {{ t('dashboard_targets.title') }}
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-for="row in performance" :key="row.target_uuid" class="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900">{{ row.branch_name ?? '—' }}</p>
                            <span class="text-xs font-semibold tabular-nums text-slate-600">{{ row.progress_pct }}%</span>
                        </div>
                        <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-200">
                            <div
                                class="h-full rounded-full transition-all"
                                :class="progressBarClass(row)"
                                :style="{ width: `${Math.min(100, row.progress_pct)}%` }"
                            />
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs text-slate-600">
                            <span class="tabular-nums">{{ row.actual }} / {{ row.goal }}</span>
                            <span>{{ elapsedLabel(row) }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-2 text-[11px] font-semibold">
                            <span v-if="row.window_count > 0" class="rounded-full bg-slate-200 px-2 py-0.5 text-slate-700">
                                {{ t('dashboard_targets.hit_rate', { hit: row.hit_count, total: row.window_count }) }}
                            </span>
                            <span v-if="row.last_window && !row.last_window.hit" class="rounded-full bg-rose-100 px-2 py-0.5 text-rose-700">
                                {{ t('dashboard_targets.last_missed') }}
                            </span>
                            <span v-else-if="row.last_window && row.last_window.hit" class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-700">
                                {{ t('dashboard_targets.last_hit') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI tiles (only when payload loaded). Six tiles →
                 3-up on large screens keeps the two rows balanced. -->
            <div v-if="summary" class="u-stagger grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <!-- Today -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard_widgets.today') }}</div>
                    <div class="mt-2 text-3xl font-bold text-slate-950 tabular-nums">{{ summary.today.gross }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ summary.today.order_count }} {{ t('dashboard_widgets.orders') }}</div>
                    <div v-if="todayVsYesterdayPct !== null" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold">
                        <TrendingUp v-if="todayVsYesterdayPct > 0" class="size-3.5 text-emerald-600" />
                        <TrendingDown v-else-if="todayVsYesterdayPct < 0" class="size-3.5 text-rose-600" />
                        <Minus v-else class="size-3.5 text-slate-500" />
                        <span :class="todayVsYesterdayPct > 0 ? 'text-emerald-700' : todayVsYesterdayPct < 0 ? 'text-rose-700' : 'text-slate-600'">
                            {{ todayVsYesterdayPct > 0 ? '+' : '' }}{{ todayVsYesterdayPct }}% {{ t('dashboard_widgets.vs_yesterday') }}
                        </span>
                    </div>
                </div>

                <!-- MTD -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard_widgets.mtd') }}</div>
                    <div class="mt-2 text-3xl font-bold text-slate-950 tabular-nums">{{ summary.mtd.gross }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ summary.mtd.order_count }} {{ t('dashboard_widgets.orders') }}</div>
                </div>

                <!-- Top product today -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <Trophy class="size-3.5 text-amber-500" />
                        {{ t('dashboard_widgets.top_product_today') }}
                    </div>
                    <template v-if="summary.top_product_today">
                        <div class="mt-2 truncate text-xl font-bold text-slate-950">{{ summary.top_product_today.product_name }}</div>
                        <div class="mt-1 text-xs text-slate-500 tabular-nums">{{ summary.top_product_today.revenue }}</div>
                    </template>
                    <div v-else class="mt-3 text-sm text-slate-400">{{ t('dashboard_widgets.no_data') }}</div>
                </div>

                <!-- Low-stock count -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <AlertTriangle class="size-3.5" :class="summary.low_stock_count > 0 ? 'text-rose-500' : 'text-slate-400'" />
                        {{ t('dashboard_widgets.low_stock') }}
                    </div>
                    <div class="mt-2 text-3xl font-bold tabular-nums" :class="summary.low_stock_count > 0 ? 'text-rose-700' : 'text-slate-950'">
                        {{ summary.low_stock_count }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">{{ t('dashboard_widgets.low_stock_subtitle') }}</div>
                </div>

                <!-- Round-up donations today (§5.2) -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <HeartHandshake class="size-3.5 text-teal-500" />
                        {{ t('dashboard_widgets.roundup_today') }}
                    </div>
                    <div class="mt-2 text-3xl font-bold text-slate-950 tabular-nums">{{ summary.roundup_today.total }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ t('dashboard_widgets.roundup_count', { count: summary.roundup_today.count }) }}</div>
                </div>

                <!-- Active devices (§5.2): online-now vs fleet total. -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm card-hover">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <MonitorSmartphone
                            class="size-3.5"
                            :class="summary.active_devices.total > 0 && summary.active_devices.online === 0 ? 'text-rose-500' : 'text-emerald-500'"
                        />
                        {{ t('dashboard_widgets.active_devices') }}
                    </div>
                    <div class="mt-2 text-3xl font-bold tabular-nums" :class="summary.active_devices.total > 0 && summary.active_devices.online === 0 ? 'text-rose-700' : 'text-slate-950'">
                        {{ summary.active_devices.online }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">{{ t('dashboard_widgets.devices_online', { total: summary.active_devices.total }) }}</div>
                </div>
            </div>

            <!-- v2 dashboard graphs -->
            <div v-if="summary" class="space-y-6">
                <!-- Period-over-period comparison (this week/month vs the previous,
                     filterable, with an up/down % delta). -->
                <SalesComparison />

                <!-- Daily sales trend (area). -->
                <ReportChart
                    type="area"
                    :title="t('dashboard_widgets.sales_trend')"
                    :series="trendChart.series"
                    :categories="trendChart.categories"
                    :height="260"
                    currency
                    hide-legend
                    :empty-text="t('dashboard_widgets.no_data')"
                />

                <!-- Sales by hour (day-of-week × hour heatmap). -->
                <SalesHeatmap
                    :title="t('dashboard_widgets.sales_by_hour')"
                    :subtitle="t('dashboard_widgets.sales_by_hour_sub', { days: summary.hour_weekday.window_days })"
                    :cells="hourCells"
                    :empty-text="t('dashboard_widgets.no_data')"
                />

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Payment mix today (§5.2) — donut of today's tenders. -->
                    <ReportChart
                        v-if="paymentMixChart.series.length"
                        type="donut"
                        :title="t('dashboard_widgets.payment_mix_today')"
                        :series="paymentMixChart.series"
                        :labels="paymentMixChart.labels"
                        currency
                    />
                    <!-- Top products — revenue-share donut. -->
                    <ReportChart
                        v-if="topProductsDonut.series.length"
                        type="donut"
                        :title="t('dashboard_widgets.top_products')"
                        :series="topProductsDonut.series"
                        :labels="topProductsDonut.labels"
                        currency
                        :empty-text="t('dashboard_widgets.no_data')"
                    />
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Top staff — revenue-share donut. -->
                    <ReportChart
                        v-if="topStaffDonut.series.length"
                        type="donut"
                        :title="t('dashboard_widgets.top_staff')"
                        :series="topStaffDonut.series"
                        :labels="topStaffDonut.labels"
                        currency
                        :empty-text="t('dashboard_widgets.no_data')"
                    />
                    <!-- Top branches — vertical bar (variety vs the horizontal ones). -->
                    <ReportChart
                        v-if="topBranchesChart.categories.length"
                        type="bar"
                        :title="t('dashboard_widgets.top_branches')"
                        :series="topBranchesChart.series"
                        :categories="topBranchesChart.categories"
                        :height="280"
                        currency
                        distributed
                        hide-legend
                    />
                </div>

                <!-- Beautiful trio: today-vs-average gauge · channel mix · busiest days -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <ReportChart
                        v-if="todayVsAvg.hasData"
                        type="radialBar"
                        :title="t('dashboard_widgets.today_vs_avg')"
                        :series="todayVsAvg.series"
                        :labels="todayVsAvg.labels"
                        :height="300"
                        :empty-text="t('dashboard_widgets.no_data')"
                    />
                    <ReportChart
                        v-if="channelMix.series.length"
                        type="polarArea"
                        :title="t('dashboard_widgets.channel_mix')"
                        :series="channelMix.series"
                        :labels="channelMix.labels"
                        :height="300"
                        currency
                        :empty-text="t('dashboard_widgets.no_data')"
                    />
                    <ReportChart
                        v-if="busiestDaysHasData"
                        type="radar"
                        :title="t('dashboard_widgets.busiest_days')"
                        :series="busiestDays.series"
                        :categories="busiestDays.categories"
                        :height="300"
                        currency
                        hide-legend
                        :empty-text="t('dashboard_widgets.no_data')"
                    />
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <ReportChart
                        v-if="topCustomersChart.categories.length"
                        type="bar"
                        :title="t('dashboard_widgets.top_customers')"
                        :series="topCustomersChart.series"
                        :categories="topCustomersChart.categories"
                        :height="Math.max(220, topCustomersChart.categories.length * 44)"
                        currency
                        horizontal
                        distributed
                        hide-legend
                    />
                    <ReportChart
                        v-if="topIngredientsChart.categories.length"
                        type="bar"
                        :title="t('dashboard_widgets.top_ingredients')"
                        :series="topIngredientsChart.series"
                        :categories="topIngredientsChart.categories"
                        :height="Math.max(220, topIngredientsChart.categories.length * 44)"
                        horizontal
                        distributed
                        hide-legend
                    />
                </div>
            </div>

            <!-- Recent activity (audit log peek) -->
            <div v-if="summary && canSeeAudit" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-base font-semibold text-slate-950">
                    <History class="size-4 text-slate-500" />
                    {{ t('dashboard_widgets.recent_audit') }}
                </h2>
                <ul v-if="summary.recent_audit_events.length" class="mt-4 space-y-3">
                    <li v-for="evt in summary.recent_audit_events" :key="evt.id" class="flex items-start gap-3 text-sm">
                        <span class="mt-1 size-2 shrink-0 rounded-full bg-teal-500" />
                        <div class="flex-1">
                            <div class="font-mono text-xs font-semibold text-slate-800">{{ evt.event }}</div>
                            <div class="text-xs text-slate-500">
                                <span v-if="evt.actor_name">{{ evt.actor_name }}</span>
                                <span v-else>System</span>
                                ·
                                <span class="tabular-nums">{{ evt.created_at }}</span>
                            </div>
                        </div>
                    </li>
                </ul>
                <div v-else class="mt-4 text-sm text-slate-500">{{ t('dashboard_widgets.no_data') }}</div>
            </div>

            <!-- Roadmap card (kept for context) -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-950">{{ t('dashboard.coming_soon_title') }}</h2>
                <ul class="mt-4 space-y-3">
                    <li v-for="(item, i) in comingSoon" :key="i" class="flex items-start gap-3 text-sm leading-relaxed text-slate-700">
                        <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-teal-600" />
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- P-G8 — "a branch just missed its target" popup, once per
             session (the dashboard is the post-login landing page). -->
        <BaseModal v-if="missPopupOpen" :title="t('dashboard_targets.miss_popup_title')" @close="missPopupOpen = false">
            <div class="space-y-3">
                <p class="text-sm text-slate-600">{{ t('dashboard_targets.miss_popup_body') }}</p>
                <div v-for="(miss, i) in recentMisses" :key="i" class="rounded-lg border border-rose-100 bg-rose-50 px-4 py-3">
                    <p class="text-sm font-bold text-rose-800">{{ miss.branch_name ?? '—' }}</p>
                    <p class="mt-0.5 text-xs text-rose-700">
                        {{ miss.window_start }} → {{ miss.window_end }} ·
                        <span class="font-semibold tabular-nums">{{ miss.actual_amount }} / {{ miss.goal_amount }}</span>
                    </p>
                </div>
                <button
                    type="button"
                    class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    @click="missPopupOpen = false"
                >{{ t('dashboard_targets.miss_popup_dismiss') }}</button>
            </div>
        </BaseModal>
    </MerchantLayout>
</template>

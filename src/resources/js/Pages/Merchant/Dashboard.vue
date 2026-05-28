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
import { Sparkles, TrendingUp, TrendingDown, Minus, Trophy, AlertTriangle, History, CheckCircle2 } from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { authState } from '@/stores/auth';
import { fetchDashboardSummary, type DashboardSummaryPayload } from '@/lib/api/dashboard';
import { ApiError } from '@/lib/api';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';

const { t, tm } = useI18n();
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

onMounted(() => { void load(); });

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

            <!-- KPI tiles (only when payload loaded) -->
            <div v-if="summary" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Today -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
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
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard_widgets.mtd') }}</div>
                    <div class="mt-2 text-3xl font-bold text-slate-950 tabular-nums">{{ summary.mtd.gross }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ summary.mtd.order_count }} {{ t('dashboard_widgets.orders') }}</div>
                </div>

                <!-- Top product today -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
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
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <AlertTriangle class="size-3.5" :class="summary.low_stock_count > 0 ? 'text-rose-500' : 'text-slate-400'" />
                        {{ t('dashboard_widgets.low_stock') }}
                    </div>
                    <div class="mt-2 text-3xl font-bold tabular-nums" :class="summary.low_stock_count > 0 ? 'text-rose-700' : 'text-slate-950'">
                        {{ summary.low_stock_count }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">{{ t('dashboard_widgets.low_stock_subtitle') }}</div>
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
    </MerchantLayout>
</template>

<script setup lang="ts">
/**
 * Period-over-period sales comparison (dashboard + branch control center).
 *
 * Compares the current week/month — or a prior one via the ◄ ► steppers —
 * against the previous equivalent period: a two-line overlay plus an up/down %
 * delta (to-date fair when the current period is still in progress). Pass
 * `branchId` to scope to one branch; omit for the actor's full branch scope.
 * Self-fetches and degrades silently (a hiccup never blanks the host page).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, ChevronRight, TrendingUp, TrendingDown, Minus } from 'lucide-vue-next';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import { fetchSalesComparison, type SalesComparisonPayload } from '@/lib/api/dashboard';

const props = defineProps<{ branchId?: number }>();

const { t, locale } = useI18n();
const isAr = computed(() => locale.value === 'ar');

const period = ref<'week' | 'month'>('week');
const offset = ref(0);
const data = ref<SalesComparisonPayload | null>(null);

async function load(): Promise<void> {
    try {
        const r = await fetchSalesComparison({ period: period.value, offset: offset.value, branchId: props.branchId });
        data.value = r.data;
    } catch {
        data.value = null;
    }
}

onMounted(load);
watch([period, offset], load);
// Reset to the current period when the branch changes. Resetting offset (when
// non-zero) reloads via the watcher above; otherwise load directly — so the
// branch change never fires two identical requests.
watch(() => props.branchId, () => {
    if (offset.value !== 0) {
        offset.value = 0;
    } else {
        void load();
    }
});

function setPeriod(p: 'week' | 'month'): void {
    if (period.value === p) return;
    period.value = p;
    offset.value = 0;
}

function num(v: string | null | undefined): number {
    const n = Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

function fmtDate(d: string): string {
    const date = new Date(`${d}T00:00:00`);
    return Number.isNaN(date.getTime())
        ? d
        : date.toLocaleDateString(isAr.value ? 'ar' : 'en-GB', { day: '2-digit', month: 'short' });
}

const rangeLabel = computed(() => (data.value ? `${fmtDate(data.value.current.from)} – ${fmtDate(data.value.current.to)}` : ''));

const chart = computed(() => {
    const cur = data.value?.current.series ?? [];
    const prev = data.value?.previous.series ?? [];
    const maxLen = Math.max(cur.length, prev.length);
    return {
        categories: Array.from({ length: maxLen }, (_, i) => t('dashboard_compare.day', { n: i + 1 })),
        series: [
            { name: t('dashboard_compare.current'), data: cur.map((p) => num(p.gross)) },
            { name: t('dashboard_compare.previous'), data: prev.map((p) => num(p.gross)) },
        ],
    };
});
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">{{ t('dashboard_compare.title') }}</h2>
                <p v-if="data" class="text-xs text-slate-400">
                    {{ rangeLabel }}<span v-if="data.in_progress"> · {{ t('dashboard_compare.so_far') }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs">
                    <button type="button" class="rounded-md px-2.5 py-1 font-semibold transition" :class="period === 'week' ? 'bg-teal-600 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="setPeriod('week')">{{ t('dashboard_compare.week') }}</button>
                    <button type="button" class="rounded-md px-2.5 py-1 font-semibold transition" :class="period === 'month' ? 'bg-teal-600 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="setPeriod('month')">{{ t('dashboard_compare.month') }}</button>
                </div>
                <div class="inline-flex items-center gap-1">
                    <button type="button" class="grid size-7 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50" :title="t('dashboard_compare.older')" @click="offset++"><ChevronLeft class="size-4" /></button>
                    <button type="button" :disabled="offset === 0" class="grid size-7 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50 disabled:opacity-40" :title="t('dashboard_compare.newer')" @click="offset = Math.max(0, offset - 1)"><ChevronRight class="size-4" /></button>
                </div>
            </div>
        </div>

        <div v-if="data" class="flex flex-wrap items-end gap-x-8 gap-y-2 px-5 pt-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard_compare.current') }}</p>
                <p class="text-2xl font-bold tabular-nums text-slate-950">{{ data.current.total }} <span class="text-sm font-medium text-slate-400">OMR</span></p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard_compare.previous') }}</p>
                <p class="text-2xl font-bold tabular-nums text-slate-500">{{ data.previous.total }} <span class="text-sm font-medium text-slate-400">OMR</span></p>
            </div>
            <div v-if="data.change_pct !== null" class="inline-flex items-center gap-1 pb-1 text-sm font-semibold">
                <TrendingUp v-if="data.change_pct > 0" class="size-4 text-emerald-600" />
                <TrendingDown v-else-if="data.change_pct < 0" class="size-4 text-rose-600" />
                <Minus v-else class="size-4 text-slate-500" />
                <span :class="data.change_pct > 0 ? 'text-emerald-700' : data.change_pct < 0 ? 'text-rose-700' : 'text-slate-600'">
                    {{ data.change_pct > 0 ? '+' : '' }}{{ data.change_pct }}%
                </span>
            </div>
        </div>

        <ReportChart
            type="line"
            :series="chart.series"
            :categories="chart.categories"
            :height="240"
            currency
            :empty-text="t('dashboard_widgets.no_data')"
        />
    </div>
</template>

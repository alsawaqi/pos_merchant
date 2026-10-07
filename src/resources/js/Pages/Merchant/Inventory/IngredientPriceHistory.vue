<script setup lang="ts">
/**
 * LAUNCH costs & allergens add-on (tester call 1) — the ingredient page's
 * "Price history" tab: every priced goods-received line of the item, newest
 * first — date, supplier, how it was bought, the price per kg / l / piece
 * and the change from the previous purchase (an alert at the threshold).
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getPriceHistory, type PriceHistoryRow } from '@/lib/api/costs';
import { changeText, friendlyUnitCost } from '@/lib/foodCost';

const props = defineProps<{ ingredientUuid: string }>();
const { t, locale } = useI18n();

const rows = ref<PriceHistoryRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const threshold = ref<number | null>(null);

function day(iso: string): string {
    return new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function boughtAs(row: PriceHistoryRow): string {
    if (row.container_label) return row.pieces ? `${row.pieces} × ${row.container_label}` : row.container_label;
    return row.purchase_unit ?? row.unit ?? '';
}

onMounted(async () => {
    try {
        const response = await getPriceHistory(props.ingredientUuid);
        rows.value = response.data;
        threshold.value = response.meta.threshold_percent;
    } catch {
        error.value = t('costs.history.load_failed');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="space-y-2" data-test="price-history">
        <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-500">…</p>
        <p v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-sm italic text-slate-500" data-test="price-history-empty">{{ t('costs.history.empty') }}</p>
        <div v-else class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-start text-[11px] uppercase tracking-wider text-slate-500">
                        <th class="px-3 py-2 text-start font-semibold">{{ t('costs.history.date') }}</th>
                        <th class="px-3 py-2 text-start font-semibold">{{ t('costs.history.supplier') }}</th>
                        <th class="px-3 py-2 text-start font-semibold">{{ t('costs.history.bought_as') }}</th>
                        <th class="px-3 py-2 text-end font-semibold">{{ t('costs.history.unit_cost', { unit: friendlyUnitCost(rows[0].unit_cost, rows[0].unit).unit }) }}</th>
                        <th class="px-3 py-2 text-end font-semibold">{{ t('costs.history.change') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.line_id" class="border-b border-slate-50 last:border-0" data-test="price-history-row">
                        <td class="px-3 py-2 text-slate-700">{{ day(row.received_at) }}</td>
                        <td class="px-3 py-2 text-slate-700">{{ row.supplier?.name ?? t('costs.alerts.no_supplier') }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ boughtAs(row) }}</td>
                        <td class="px-3 py-2 text-end font-semibold tabular-nums text-slate-900">{{ friendlyUnitCost(row.unit_cost, row.unit).amount }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">
                            <span v-if="row.change_pct === null" class="text-xs text-slate-400">{{ t('costs.history.first') }}</span>
                            <span v-else :class="row.alert ? 'font-bold text-rose-700' : 'text-slate-600'">
                                <bdi dir="ltr">{{ changeText(row.change_pct) }}</bdi>
                                <span v-if="row.alert" class="ms-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase" data-test="price-history-alert">{{ t('costs.history.alert') }}</span>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="threshold !== null && rows.length > 0" class="text-xs text-slate-500">{{ t('costs.alerts.intro', { threshold }) }}</p>
    </div>
</template>

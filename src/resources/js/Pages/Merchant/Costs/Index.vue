<script setup lang="ts">
/**
 * LAUNCH costs & allergens add-on (tester calls 1 and 2) — the Costs page:
 *
 *   Price alerts        goods-received prices that moved by the threshold or
 *                       more from the previous purchase (last 30 days): old
 *                       and new price, change, supplier, the dishes affected
 *                       with their new cost and food cost % (crossed the
 *                       target flagged); a manager marks one seen
 *   Dishes over target  every dish (products, combos, meals with each main)
 *                       over its target food cost %, or all of them
 *   Settings            the alert threshold (inventory.manage) and the
 *                       company target food cost % (catalogue.manage)
 *
 * Reading needs reports.view (the server gates every call).
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';
import { ApiError } from '@/lib/api';
import {
    getCostSettings,
    listFoodCosts,
    listPriceAlerts,
    markPriceAlertSeen,
    updateCostSettings,
    type FoodCostListRow,
    type PriceAlert,
} from '@/lib/api/costs';
import { baisasText, changeText, foodCostTone, friendlyUnitCost, pctText } from '@/lib/foodCost';

type Tab = 'alerts' | 'dishes' | 'settings';
const TABS: Tab[] = ['alerts', 'dishes', 'settings'];

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const { can } = usePermissions();
const canMarkSeen = computed(() => can(MerchantPermission.InventoryManage));
const canEditThreshold = computed(() => can(MerchantPermission.InventoryManage));
const canEditTarget = computed(() => can(MerchantPermission.CatalogueManage));

const tab = ref<Tab>('alerts');
const alerts = ref<PriceAlert[]>([]);
const alertsMeta = ref<{ days: number; threshold_percent: number } | null>(null);
const showSeen = ref(true);
const dishes = ref<FoodCostListRow[]>([]);
const overOnly = ref(true);
const loading = ref(false);
const error = ref<string | null>(null);
const settings = reactive({ price_alert_threshold_percent: '', target_food_cost_percent: '' });
const settingsMessage = ref<string | null>(null);
const settingsError = ref<string | null>(null);
const settingsErrors = ref<Record<string, string[]>>({});
const saving = ref(false);
const busyLine = ref<number | null>(null);

const shownAlerts = computed(() => (showSeen.value ? alerts.value : alerts.value.filter((a) => !a.seen)));

function day(iso: string): string {
    return new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function name(row: { name: string | null; name_ar: string | null }): string {
    return (locale.value === 'ar' && row.name_ar ? row.name_ar : row.name) ?? '';
}

function dishLink(row: FoodCostListRow): string {
    if (row.type === 'meal' && row.meal_uuid) return `/catalogue/meals/${row.meal_uuid}/edit`;
    return row.product_type === 'combo' ? `/catalogue/combos/${row.product_uuid}/edit` : `/catalogue/products/${row.product_uuid}/edit`;
}

async function loadAlerts(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listPriceAlerts();
        alerts.value = response.data;
        alertsMeta.value = response.meta;
    } catch {
        error.value = t('costs.alerts.load_failed');
    } finally {
        loading.value = false;
    }
}

async function loadDishes(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        dishes.value = (await listFoodCosts(overOnly.value)).data;
    } catch {
        error.value = t('costs.dishes.load_failed');
    } finally {
        loading.value = false;
    }
}

async function loadSettings(): Promise<void> {
    try {
        const data = (await getCostSettings()).data;
        settings.price_alert_threshold_percent = String(Number(data.price_alert_threshold_percent));
        settings.target_food_cost_percent = String(Number(data.target_food_cost_percent));
    } catch {
        settingsError.value = t('costs.settings.save_failed');
    }
}

async function markSeen(alert: PriceAlert): Promise<void> {
    busyLine.value = alert.line_id;
    try {
        await markPriceAlertSeen(alert.line_id);
        await loadAlerts();
    } catch {
        error.value = t('costs.alerts.mark_failed');
    } finally {
        busyLine.value = null;
    }
}

async function saveSettings(): Promise<void> {
    saving.value = true;
    settingsMessage.value = null;
    settingsError.value = null;
    settingsErrors.value = {};
    try {
        const body: Record<string, string> = {};
        if (canEditThreshold.value) body.price_alert_threshold_percent = String(settings.price_alert_threshold_percent).trim();
        if (canEditTarget.value) body.target_food_cost_percent = String(settings.target_food_cost_percent).trim();
        await updateCostSettings(body);
        settingsMessage.value = t('costs.settings.saved');
        await loadSettings();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) settingsErrors.value = err.payload.errors;
        settingsError.value = t('costs.settings.save_failed');
    } finally {
        saving.value = false;
    }
}

function open(next: Tab): void {
    tab.value = next;
    void router.replace({ query: { ...route.query, tab: next } });
}

watch(tab, (next) => {
    if (next === 'alerts') void loadAlerts();
    if (next === 'dishes') void loadDishes();
    if (next === 'settings') void loadSettings();
});
watch(overOnly, () => void loadDishes());

onMounted(() => {
    const requested = String(route.query.tab ?? '');
    if (requested === 'dishes' || requested === 'settings') {
        tab.value = requested;
    } else {
        void loadAlerts();
    }
});
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-6xl space-y-4">
            <header>
                <h1 class="text-2xl font-bold text-slate-950">{{ t('costs.title') }}</h1>
                <p class="text-sm text-slate-500">{{ t('costs.subtitle') }}</p>
            </header>

            <nav class="flex flex-wrap gap-2" data-test="costs-tabs">
                <button
                    v-for="k in TABS"
                    :key="k"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                    :class="tab === k ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 shadow-sm hover:bg-slate-50'"
                    :data-test="`costs-tab-${k}`"
                    @click="open(k)"
                >{{ t(`costs.tabs.${k}`) }}</button>
            </nav>

            <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

            <!-- ============ PRICE ALERTS ============ -->
            <section v-if="tab === 'alerts'" class="space-y-3" data-test="price-alerts">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p v-if="alertsMeta" class="text-xs text-slate-500">{{ t('costs.alerts.intro', { threshold: alertsMeta.threshold_percent }) }}</p>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="showSeen" type="checkbox" class="rounded border-slate-300 text-teal-600" data-test="price-alerts-show-seen">
                        {{ t('costs.alerts.show_seen') }}
                    </label>
                </div>
                <p v-if="!loading && shownAlerts.length === 0" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500" data-test="price-alerts-empty">
                    {{ t('costs.alerts.empty', { days: alertsMeta?.days ?? 30 }) }}
                </p>
                <article v-for="alert in shownAlerts" :key="alert.line_id" class="rounded-2xl border bg-white p-4 shadow-sm" :class="alert.seen ? 'border-slate-200 opacity-75' : 'border-amber-200'" data-test="price-alert">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">{{ name(alert.ingredient) }}</h2>
                            <p class="text-xs text-slate-500">{{ day(alert.received_at) }} · {{ alert.supplier?.name ?? t('costs.alerts.no_supplier') }}</p>
                        </div>
                        <div class="text-end">
                            <p class="text-sm tabular-nums text-slate-700">
                                {{ friendlyUnitCost(alert.old_unit_cost, alert.ingredient.unit).amount }} → <span class="font-semibold text-slate-950">{{ friendlyUnitCost(alert.new_unit_cost, alert.ingredient.unit).amount }}</span>
                                <span class="text-xs text-slate-500">OMR {{ t('costs.alerts.per_unit', { unit: friendlyUnitCost(alert.new_unit_cost, alert.ingredient.unit).unit }) }}</span>
                            </p>
                            <p class="text-sm font-bold tabular-nums" :class="alert.change_pct > 0 ? 'text-rose-700' : 'text-emerald-700'" data-test="price-alert-change"><bdi dir="ltr">{{ changeText(alert.change_pct) }}</bdi></p>
                        </div>
                    </div>
                    <div v-if="alert.dishes !== null" class="mt-3" data-test="price-alert-dishes">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('costs.alerts.dishes') }}</p>
                        <p v-if="alert.dishes.length === 0" class="text-xs italic text-slate-400">{{ t('costs.alerts.no_dishes') }}</p>
                        <ul v-else class="mt-1 space-y-0.5 text-sm">
                            <li v-for="dish in alert.dishes" :key="dish.product_uuid" class="flex flex-wrap items-center gap-2" data-test="price-alert-dish">
                                <span class="text-slate-800">{{ name(dish) }}</span>
                                <span class="tabular-nums text-slate-600">{{ baisasText(dish.cost_baisas) }} OMR</span>
                                <span class="tabular-nums" :class="dish.over_target ? 'font-semibold text-rose-700' : 'text-slate-600'">{{ pctText(dish.food_cost_pct) ?? t(`costs.food_cost.${dish.status}`) }}</span>
                                <span class="text-xs text-slate-400">{{ t('costs.dishes.target') }} {{ pctText(dish.target_pct) }}</span>
                                <span v-if="dish.crossed_target" class="rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold uppercase text-white" data-test="price-alert-crossed">{{ t('costs.alerts.crossed') }}</span>
                                <span v-else-if="dish.over_target" class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-700">{{ t('costs.alerts.over') }}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                        <RouterLink :to="`/inventory/receipts/${alert.receipt_uuid}`" class="text-xs font-semibold text-teal-700 hover:underline">{{ alert.receipt_reference ?? alert.receipt_uuid.slice(0, 8) }}</RouterLink>
                        <span v-if="alert.seen" class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600" data-test="price-alert-seen">{{ alert.seen_by ? t('costs.alerts.seen_by', { name: alert.seen_by }) : t('costs.alerts.seen') }}</span>
                        <button
                            v-else-if="canMarkSeen"
                            type="button"
                            class="rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                            :disabled="busyLine === alert.line_id"
                            data-test="price-alert-mark-seen"
                            @click="markSeen(alert)"
                        >{{ t('costs.alerts.mark_seen') }}</button>
                    </div>
                </article>
            </section>

            <!-- ============ DISHES OVER TARGET ============ -->
            <section v-else-if="tab === 'dishes'" class="space-y-3" data-test="dishes-over-target">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input :checked="!overOnly" type="checkbox" class="rounded border-slate-300 text-teal-600" data-test="dishes-show-all" @change="overOnly = !overOnly">
                    {{ t('costs.dishes.show_all') }}
                </label>
                <p v-if="!loading && dishes.length === 0" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500" data-test="dishes-empty">{{ t('costs.dishes.empty') }}</p>
                <div v-else class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-start">{{ t('costs.dishes.dish') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('costs.dishes.cost') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('costs.dishes.price') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('costs.dishes.food_cost') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('costs.dishes.target') }}</th>
                                <th class="px-4 py-2 text-end">{{ t('costs.dishes.over_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in dishes" :key="`${row.type}-${row.meal_uuid ?? ''}-${row.product_uuid}`" class="border-b border-slate-100 last:border-0" data-test="dish-row">
                                <td class="px-4 py-2">
                                    <RouterLink :to="dishLink(row)" class="font-medium text-slate-900 hover:underline">{{ name(row) }}</RouterLink>
                                    <span v-if="row.type === 'meal'" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">{{ t('costs.dishes.meal') }}</span>
                                    <span v-else-if="row.product_type === 'combo'" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">{{ t('costs.dishes.combo') }}</span>
                                    <!-- K-4 — an inactive or not-on-sale dish shows why the dashboard does not count it. -->
                                    <span v-if="row.product_status !== 'active'" class="ms-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600" data-test="dish-inactive">{{ t('costs.dishes.inactive') }}</span>
                                    <span v-else-if="!row.on_sale" class="ms-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600" data-test="dish-not-on-sale">{{ t('costs.dishes.not_on_sale') }}</span>
                                    <span v-if="row.status === 'ok' && !row.cost_complete" class="block text-[10px] italic text-amber-700">{{ t('costs.dishes.partial') }}</span>
                                </td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ row.cost_baisas === null ? '—' : baisasText(row.cost_baisas) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ baisasText(row.net_price_baisas) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums" :class="foodCostTone(row) === 'over' ? 'font-bold text-rose-700' : ''">{{ pctText(row.food_cost_pct) ?? t(`costs.food_cost.${row.status}`) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums text-slate-600">{{ pctText(row.target_pct) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">
                                    <span v-if="row.over_target" class="rounded bg-rose-100 px-1.5 py-0.5 font-semibold text-rose-700">+{{ pctText(row.over_by_pct) }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============ SETTINGS ============ -->
            <section v-else class="max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="costs-settings">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('costs.settings.threshold') }}</span>
                    <input v-model="settings.price_alert_threshold_percent" type="number" step="0.1" min="0.1" max="1000" :disabled="!canEditThreshold" class="mt-1 w-40 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums disabled:bg-slate-50" data-test="costs-threshold">
                    <span class="mt-1 block text-xs text-slate-500">{{ t('costs.settings.threshold_hint') }}</span>
                    <span v-if="settingsErrors.price_alert_threshold_percent" class="mt-1 block text-xs text-rose-600">{{ settingsErrors.price_alert_threshold_percent[0] }}</span>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('costs.settings.target') }}</span>
                    <input v-model="settings.target_food_cost_percent" type="number" step="0.1" min="0.1" max="100" :disabled="!canEditTarget" class="mt-1 w-40 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums disabled:bg-slate-50" data-test="costs-target">
                    <span class="mt-1 block text-xs text-slate-500">{{ t('costs.settings.target_hint') }}</span>
                    <span v-if="settingsErrors.target_food_cost_percent" class="mt-1 block text-xs text-rose-600">{{ settingsErrors.target_food_cost_percent[0] }}</span>
                </label>
                <div class="flex items-center gap-3">
                    <button
                        v-if="canEditThreshold || canEditTarget"
                        type="button"
                        class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="saving"
                        data-test="costs-settings-save"
                        @click="saveSettings"
                    >{{ t('costs.settings.save') }}</button>
                    <span v-if="settingsMessage" class="text-sm text-emerald-700">{{ settingsMessage }}</span>
                    <span v-if="settingsError" class="text-sm text-rose-700">{{ settingsError }}</span>
                </div>
            </section>
        </div>
    </MerchantLayout>
</template>

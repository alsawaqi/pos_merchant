<script setup lang="ts">
/**
 * Customer 360 (v2 #8) — read-focused detail page.
 *
 * Consolidates the data that previously lived only in the Customers
 * list modals plus the new analytics/orders endpoints:
 *   - header: name, phone, vehicle plates, wallet balance, member-since
 *   - lifetime rollups: spend / paid orders / avg ticket / last order
 *   - favorite item + a 12-month spend trend chart
 *   - loyalty accounts (per rule) + recent loyalty/wallet activity
 *   - paginated order history → click a row to open the order drawer
 *
 * Each section is fetched independently and gated by the same
 * permission the server enforces, so a user without reports/loyalty
 * view just doesn't see that section (no error noise). Adjusting
 * loyalty/wallet stays on the Customers list modal — this page reads.
 */

import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeft, Cake, Car, Tag, Wallet, Trophy, Receipt, History, Coins,
} from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ReportChart from '@/Pages/Merchant/Reports/components/ReportChart.vue';
import OrderDetailDrawer from '@/Pages/Merchant/Orders/components/OrderDetailDrawer.vue';
import {
    getCustomer, getCustomerAnalytics, getCustomerOrders,
    type Customer, type CustomerAnalytics, type CustomerOrdersPayload,
} from '@/lib/api/customers';
import {
    getCustomerLoyalty, getLoyaltyTransactions, getWalletLedger,
    type CustomerLoyaltySummary, type PaginatedTransactions, type PaginatedWallet,
} from '@/lib/api/loyalty';
import { ApiError } from '@/lib/api';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';

const route = useRoute();
const { t } = useI18n();
const { can } = usePermissions();

const uuid = String(route.params.uuid);

const customer = ref<Customer | null>(null);
const analytics = ref<CustomerAnalytics | null>(null);
const loyalty = ref<CustomerLoyaltySummary | null>(null);
const orders = ref<CustomerOrdersPayload | null>(null);
const ordersPage = ref(1);

const loading = ref(true);
const error = ref<string | null>(null);

/** uuid of the order whose detail drawer is open (null = closed). */
const detailUuid = ref<string | null>(null);

const canReports = can(MerchantPermission.ReportsView);
const canLoyalty = can(MerchantPermission.LoyaltyView);

function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

function monthLabel(ym: string): string {
    const d = new Date(`${ym}-01T00:00:00`);
    return Number.isNaN(d.getTime()) ? ym : d.toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString();
}

function orderTypeLabel(type: string | null): string {
    if (!type) return '—';
    const key = `orders.types.${type}`;
    const label = t(key);
    return label !== key ? label : type.replace(/_/g, ' ');
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    const key = `orders.statuses.${status}`;
    const label = t(key);
    return label !== key ? label : status;
}

function statusClass(status: string | null): string {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700';
        case 'open': return 'bg-amber-100 text-amber-700';
        case 'void': return 'bg-rose-100 text-rose-700';
        case 'refunded': return 'bg-slate-200 text-slate-700';
        default: return 'bg-slate-100 text-slate-600';
    }
}

async function loadCore(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await getCustomer(uuid);
        customer.value = r.data;
    } catch (err) {
        error.value = err instanceof ApiError
            ? (err.status === 404 ? t('customers.show.load_failed') : `HTTP ${err.status}`)
            : t('customers.show.load_failed');
    } finally {
        loading.value = false;
    }
}

async function loadAnalytics(): Promise<void> {
    if (!canReports) return;
    try {
        analytics.value = (await getCustomerAnalytics(uuid)).data;
    } catch {
        analytics.value = null;
    }
}

async function loadLoyalty(): Promise<void> {
    if (!canLoyalty) return;
    try {
        loyalty.value = (await getCustomerLoyalty(uuid)).data;
    } catch {
        loyalty.value = null;
    }
}

async function loadOrders(): Promise<void> {
    if (!canReports) return;
    try {
        orders.value = (await getCustomerOrders(uuid, ordersPage.value)).data;
    } catch {
        orders.value = null;
    }
}

function goOrdersPage(page: number): void {
    ordersPage.value = page;
    void loadOrders();
}

onMounted(() => {
    void loadCore();
    void loadAnalytics();
    void loadLoyalty();
    void loadOrders();
});

// Spend-trend chart series, recomputed when analytics lands.
const trendSeries = ref<{ name: string; data: number[] }[]>([]);
const trendCategories = ref<string[]>([]);
watch(analytics, (a) => {
    const pts = a?.spend_trend ?? [];
    trendCategories.value = pts.map((p) => monthLabel(p.month));
    trendSeries.value = [{ name: t('customers.show.rollups.total_spend'), data: pts.map((p) => num(p.gross)) }];
});

// ---- #3b: "view all" paginated loyalty + wallet history ----
// Default view shows the recent slice from getCustomerLoyalty; toggling
// "view all" loads the full paginated ledger from the dedicated endpoints.
const PER = 10;
const loyaltyAll = ref(false);
const loyaltyTxns = ref<PaginatedTransactions | null>(null);
const walletAll = ref(false);
const walletLedger = ref<PaginatedWallet | null>(null);

const loyaltyRows = computed(() =>
    loyaltyAll.value && loyaltyTxns.value ? loyaltyTxns.value.data : (loyalty.value?.recent_transactions ?? []),
);
const walletRows = computed(() =>
    walletAll.value && walletLedger.value ? walletLedger.value.data : (loyalty.value?.recent_wallet ?? []),
);

async function loadLoyaltyTxns(page: number): Promise<void> {
    try {
        loyaltyTxns.value = await getLoyaltyTransactions(uuid, page, PER);
    } catch { /* keep current view */ }
}
async function toggleLoyaltyAll(): Promise<void> {
    loyaltyAll.value = !loyaltyAll.value;
    if (loyaltyAll.value && loyaltyTxns.value === null) await loadLoyaltyTxns(1);
}
async function loadWalletLedger(page: number): Promise<void> {
    try {
        walletLedger.value = await getWalletLedger(uuid, page, PER);
    } catch { /* keep current view */ }
}
async function toggleWalletAll(): Promise<void> {
    walletAll.value = !walletAll.value;
    if (walletAll.value && walletLedger.value === null) await loadWalletLedger(1);
}
</script>

<template>
    <MerchantLayout>
        <div class="max-w-6xl">
            <RouterLink
                to="/customers"
                class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900"
            >
                <ArrowLeft class="size-3.5" />
                {{ t('customers.show.back') }}
            </RouterLink>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                {{ t('customers.show.loading') }}
            </div>
            <div v-else-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">
                {{ error }}
            </div>

            <div v-else-if="customer" class="space-y-6">
                <!-- Header -->
                <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h1 class="text-2xl font-bold text-slate-950">{{ customer.name }}</h1>
                            <p class="mt-0.5 text-sm text-slate-500">{{ customer.phone }}</p>
                            <!-- D3 — tag chips + upcoming-birthday badge -->
                            <div v-if="(customer.tags ?? []).length > 0 || customer.upcoming_birthday" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="tg in customer.tags"
                                    :key="tg"
                                    class="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700"
                                >
                                    <Tag class="size-3" />{{ tg }}
                                </span>
                                <span
                                    v-if="customer.upcoming_birthday"
                                    class="inline-flex items-center gap-1 rounded-full bg-pink-50 px-2.5 py-1 text-xs font-semibold text-pink-600"
                                >
                                    <Cake class="size-3" />{{ t('customers.birthday_soon') }}
                                </span>
                            </div>
                            <div v-if="customer.vehicle_plates && customer.vehicle_plates.length" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="p in customer.vehicle_plates"
                                    :key="p.id"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 font-mono text-xs font-semibold text-slate-700"
                                >
                                    <Car class="size-3.5 text-slate-400" />{{ p.plate_number }}
                                </span>
                            </div>
                            <p v-if="customer.date_of_birth" class="mt-3 text-xs text-slate-400">
                                {{ t('customers.show.date_of_birth') }}: {{ formatDate(customer.date_of_birth) }}
                            </p>
                            <p class="mt-3 text-xs text-slate-400">{{ t('customers.show.member_since') }}: {{ formatDate(customer.created_at) }}</p>
                        </div>
                        <div class="rounded-xl bg-emerald-50 px-4 py-3 text-end">
                            <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                <Wallet class="size-3.5" />{{ t('customers.show.wallet_balance') }}
                            </div>
                            <div class="mt-1 text-2xl font-bold tabular-nums text-emerald-900">{{ customer.wallet_balance }} <span class="text-sm font-medium">OMR</span></div>
                        </div>
                    </div>
                </header>

                <!-- Lifetime rollups -->
                <div v-if="analytics" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.rollups.total_spend') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ analytics.rollups.total_spend }} <span class="text-xs font-medium text-slate-400">OMR</span></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.rollups.order_count') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ analytics.rollups.order_count }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.rollups.avg_ticket') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ analytics.rollups.avg_ticket }} <span class="text-xs font-medium text-slate-400">OMR</span></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.rollups.last_order') }}</div>
                        <div class="mt-2 text-sm font-semibold tabular-nums text-slate-900">{{ formatDate(analytics.rollups.last_order_at) }}</div>
                    </div>
                </div>

                <!-- Favorite + spend trend -->
                <div v-if="analytics" class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
                    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <Trophy class="size-4 text-amber-500" />{{ t('customers.show.favorite_item') }}
                        </h2>
                        <template v-if="analytics.favorite_item">
                            <p class="mt-3 text-xl font-bold text-slate-950">{{ analytics.favorite_item.product_name }}</p>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ t('customers.show.favorite_hint', { qty: analytics.favorite_item.total_qty, count: analytics.favorite_item.line_count }) }}
                            </p>
                            <p class="mt-1 text-sm tabular-nums text-slate-600">{{ analytics.favorite_item.total_revenue }} <span class="text-xs text-slate-400">OMR</span></p>
                        </template>
                        <p v-else class="mt-3 text-sm text-slate-400">{{ t('customers.show.no_favorite') }}</p>
                    </section>

                    <ReportChart
                        type="area"
                        :title="t('customers.show.spend_trend')"
                        :series="trendSeries"
                        :categories="trendCategories"
                        :height="240"
                        currency
                        hide-legend
                        :empty-text="t('customers.show.no_orders')"
                    />
                </div>

                <!-- Loyalty + wallet -->
                <section v-if="loyalty" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-slate-950">
                        <Coins class="size-4 text-slate-500" />{{ t('customers.show.loyalty') }}
                    </h2>

                    <div v-if="loyalty.accounts.length" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="acc in loyalty.accounts" :key="acc.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="truncate text-sm font-semibold text-slate-800">{{ acc.rule?.name ?? '—' }}</div>
                            <div class="mt-2 flex items-baseline gap-3">
                                <span class="text-xl font-bold tabular-nums text-slate-950">{{ acc.point_balance }} <span class="text-xs font-medium text-slate-400">{{ t('customers.show.points') }}</span></span>
                                <span class="text-xl font-bold tabular-nums text-slate-950">{{ acc.stamp_count }} <span class="text-xs font-medium text-slate-400">{{ t('customers.show.stamps') }}</span></span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-400">{{ t('customers.show.no_loyalty') }}</p>

                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.recent_loyalty') }}</h3>
                                <button type="button" class="text-xs font-semibold text-teal-700 transition hover:underline" @click="toggleLoyaltyAll">
                                    {{ loyaltyAll ? t('customers.show.show_recent') : t('customers.show.view_all') }}
                                </button>
                            </div>
                            <ul v-if="loyaltyRows.length" class="space-y-1.5 text-sm">
                                <li v-for="tx in loyaltyRows" :key="tx.id" class="flex items-center justify-between gap-3">
                                    <span class="text-slate-600">{{ formatDateTime(tx.occurred_at) }}</span>
                                    <span class="font-semibold tabular-nums" :class="(tx.points_delta + tx.stamps_delta) >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                                        <template v-if="tx.points_delta">{{ tx.points_delta > 0 ? '+' : '' }}{{ tx.points_delta }} {{ t('customers.show.points') }}</template>
                                        <template v-if="tx.stamps_delta"> {{ tx.stamps_delta > 0 ? '+' : '' }}{{ tx.stamps_delta }} {{ t('customers.show.stamps') }}</template>
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-slate-400">{{ t('customers.show.no_activity') }}</p>
                            <div v-if="loyaltyAll && loyaltyTxns && loyaltyTxns.last_page > 1" class="mt-2 flex items-center justify-between text-xs text-slate-500">
                                <span>{{ t('orders.pagination', { page: loyaltyTxns.current_page, last: loyaltyTxns.last_page, total: loyaltyTxns.total }) }}</span>
                                <span class="flex gap-1.5">
                                    <button type="button" class="rounded border border-slate-200 px-2 py-1 font-semibold disabled:opacity-50" :disabled="loyaltyTxns.current_page <= 1" @click="loadLoyaltyTxns(loyaltyTxns!.current_page - 1)">{{ t('orders.prev') }}</button>
                                    <button type="button" class="rounded border border-slate-200 px-2 py-1 font-semibold disabled:opacity-50" :disabled="loyaltyTxns.current_page >= loyaltyTxns.last_page" @click="loadLoyaltyTxns(loyaltyTxns!.current_page + 1)">{{ t('orders.next') }}</button>
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.show.recent_wallet') }}</h3>
                                <button type="button" class="text-xs font-semibold text-teal-700 transition hover:underline" @click="toggleWalletAll">
                                    {{ walletAll ? t('customers.show.show_recent') : t('customers.show.view_all') }}
                                </button>
                            </div>
                            <ul v-if="walletRows.length" class="space-y-1.5 text-sm">
                                <li v-for="w in walletRows" :key="w.id" class="flex items-center justify-between gap-3">
                                    <span class="text-slate-600">{{ formatDateTime(w.occurred_at) }}</span>
                                    <span class="font-semibold tabular-nums" :class="num(w.amount_delta) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ w.amount_delta }} <span class="text-xs text-slate-400">OMR</span></span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-slate-400">{{ t('customers.show.no_activity') }}</p>
                            <div v-if="walletAll && walletLedger && walletLedger.last_page > 1" class="mt-2 flex items-center justify-between text-xs text-slate-500">
                                <span>{{ t('orders.pagination', { page: walletLedger.current_page, last: walletLedger.last_page, total: walletLedger.total }) }}</span>
                                <span class="flex gap-1.5">
                                    <button type="button" class="rounded border border-slate-200 px-2 py-1 font-semibold disabled:opacity-50" :disabled="walletLedger.current_page <= 1" @click="loadWalletLedger(walletLedger!.current_page - 1)">{{ t('orders.prev') }}</button>
                                    <button type="button" class="rounded border border-slate-200 px-2 py-1 font-semibold disabled:opacity-50" :disabled="walletLedger.current_page >= walletLedger.last_page" @click="loadWalletLedger(walletLedger!.current_page + 1)">{{ t('orders.next') }}</button>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Order history -->
                <section v-if="orders" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <Receipt class="size-4 text-slate-500" />{{ t('customers.show.order_history') }}
                    </h2>
                    <table v-if="orders.rows.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('orders.columns.time') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('orders.columns.order') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('orders.columns.branch') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('orders.columns.type') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('orders.columns.status') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('orders.columns.items') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('orders.columns.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in orders.rows"
                                :key="row.id"
                                class="cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-slate-50"
                                @click="detailUuid = row.uuid"
                            >
                                <td class="px-5 py-2 text-xs tabular-nums text-slate-600">{{ formatDateTime(row.opened_at) }}</td>
                                <td class="px-5 py-2 font-mono text-xs font-semibold text-teal-700">{{ row.uuid.slice(0, 8).toUpperCase() }}</td>
                                <td class="px-5 py-2 text-slate-700">{{ row.branch_name ?? '—' }}</td>
                                <td class="px-5 py-2 text-slate-700">{{ orderTypeLabel(row.order_type) }}</td>
                                <td class="px-5 py-2">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
                                </td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ row.items_count }}</td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.grand_total }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('customers.show.no_orders') }}</div>

                    <div v-if="orders.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <div>{{ t('orders.pagination', { page: orders.meta.current_page, last: orders.meta.last_page, total: orders.meta.total }) }}</div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="orders.meta.current_page <= 1" @click="goOrdersPage(orders!.meta.current_page - 1)">
                                {{ t('orders.prev') }}
                            </button>
                            <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="orders.meta.current_page >= orders.meta.last_page" @click="goOrdersPage(orders!.meta.current_page + 1)">
                                {{ t('orders.next') }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <OrderDetailDrawer v-model:uuid="detailUuid" />
    </MerchantLayout>
</template>

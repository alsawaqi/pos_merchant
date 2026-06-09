<script setup lang="ts">
/**
 * Branch detail (v2 #11) — read-focused page.
 *
 * Consolidates the branch header + the new per-branch endpoints:
 *   - sales snapshot (today + MTD)
 *   - products carried here (+ per-branch availability + unit stock)
 *   - staff assigned to the branch
 *   - admin-assigned devices (reused endpoint)
 *   - recent activity: orders (click → order drawer), shifts, movements
 *
 * Each section is permission-gated to match the server, so a user
 * without catalogue/pos_staff/reports view just doesn't see it.
 */

import { onMounted, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeft, Package, Users, MonitorSmartphone, Receipt, Clock, Boxes,
} from 'lucide-vue-next';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import OrderDetailDrawer from '@/Pages/Merchant/Orders/components/OrderDetailDrawer.vue';
import SalesHeatmap from '@/Pages/Merchant/Reports/components/SalesHeatmap.vue';
import {
    showMerchantBranch, getBranchProducts, getBranchStaff, getBranchActivity, listBranchDevices,
    type MerchantBranch, type BranchProductRow, type BranchStaffMember, type BranchActivity, type BranchDevice,
} from '@/lib/api/branches';
import { ApiError } from '@/lib/api';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';

const route = useRoute();
const { t } = useI18n();
const { can } = usePermissions();

const uuid = String(route.params.uuid);

const branch = ref<MerchantBranch | null>(null);
const products = ref<BranchProductRow[] | null>(null);
const staff = ref<BranchStaffMember[] | null>(null);
const activity = ref<BranchActivity | null>(null);
const devices = ref<BranchDevice[] | null>(null);

const loading = ref(true);
const error = ref<string | null>(null);
const detailUuid = ref<string | null>(null);

const canCatalogue = can(MerchantPermission.CatalogueView);
const canStaff = can(MerchantPermission.PosStaffView);
const canReports = can(MerchantPermission.ReportsView);

function humanize(v: string | null): string {
    return v ? v.replace(/_/g, ' ') : '—';
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function orderTypeLabel(type: string | null): string {
    if (!type) return '—';
    const key = `orders.types.${type}`;
    const label = t(key);
    return label !== key ? label : humanize(type);
}

function orderStatusLabel(status: string | null): string {
    if (!status) return '—';
    const key = `orders.statuses.${status}`;
    const label = t(key);
    return label !== key ? label : status;
}

function orderStatusClass(status: string | null): string {
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
        branch.value = (await showMerchantBranch(uuid)).data;
    } catch (err) {
        error.value = err instanceof ApiError
            ? (err.status === 404 ? t('branches.show.load_failed') : `HTTP ${err.status}`)
            : t('branches.show.load_failed');
    } finally {
        loading.value = false;
    }
}

async function safe<T>(fn: () => Promise<{ data: T }>, target: { value: T | null }): Promise<void> {
    try {
        target.value = (await fn()).data;
    } catch {
        target.value = null;
    }
}

onMounted(() => {
    void loadCore();
    void safe(() => listBranchDevices(uuid), devices);
    if (canCatalogue) void safe(() => getBranchProducts(uuid), products);
    if (canStaff) void safe(() => getBranchStaff(uuid), staff);
    if (canReports) void safe(() => getBranchActivity(uuid), activity);
});
</script>

<template>
    <MerchantLayout>
        <div class="max-w-6xl">
            <RouterLink
                to="/branches"
                class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900"
            >
                <ArrowLeft class="size-3.5" />
                {{ t('branches.show.back') }}
            </RouterLink>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                {{ t('branches.show.loading') }}
            </div>
            <div v-else-if="error" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">
                {{ error }}
            </div>

            <div v-else-if="branch" class="space-y-6">
                <!-- Header -->
                <header class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-bold text-slate-950">{{ branch.name }}</h1>
                                <span v-if="branch.code" class="rounded-lg bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-600">{{ branch.code }}</span>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="branch.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                >{{ branch.status === 'active' ? t('branches.statuses.active') : t('branches.statuses.inactive') }}</span>
                            </div>
                            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                <div v-if="branch.manager_name" class="flex gap-2"><dt class="text-slate-500">{{ t('branches.fields.manager_name') }}:</dt><dd class="font-medium text-slate-800">{{ branch.manager_name }}</dd></div>
                                <div v-if="branch.phone" class="flex gap-2"><dt class="text-slate-500">{{ t('branches.fields.phone') }}:</dt><dd class="font-medium text-slate-800">{{ branch.phone }}</dd></div>
                                <div v-if="branch.email" class="flex gap-2"><dt class="text-slate-500">{{ t('branches.fields.email') }}:</dt><dd class="font-medium text-slate-800">{{ branch.email }}</dd></div>
                                <div v-if="branch.address" class="flex gap-2"><dt class="text-slate-500">{{ t('branches.fields.address') }}:</dt><dd class="font-medium text-slate-800">{{ branch.address }}</dd></div>
                            </dl>
                        </div>
                    </div>
                </header>

                <!-- Sales snapshot -->
                <div v-if="activity" class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('branches.show.sales_today') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-teal-900">{{ activity.sales.today.gross }} <span class="text-sm font-medium">OMR</span></div>
                        <div class="mt-0.5 text-xs text-teal-700">{{ activity.sales.today.count }} {{ t('orders.totals.count') }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.show.sales_mtd') }}</div>
                        <div class="mt-2 text-2xl font-bold tabular-nums text-slate-950">{{ activity.sales.mtd.gross }} <span class="text-sm font-medium text-slate-400">OMR</span></div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ activity.sales.mtd.count }} {{ t('orders.totals.count') }}</div>
                    </div>
                </div>

                <!-- Sales-by-hour performance heatmap -->
                <SalesHeatmap
                    v-if="activity"
                    :title="t('branches.show.sales_by_hour')"
                    :subtitle="t('branches.show.sales_by_hour_sub', { days: activity.hour_weekday.window_days })"
                    :cells="activity.hour_weekday.cells"
                    :empty-text="t('branches.show.no_activity')"
                />

                <!-- Products -->
                <section v-if="products" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <Package class="size-4 text-slate-500" />{{ t('branches.show.products') }}
                    </h2>
                    <table v-if="products.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('customers.table.name') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('catalogue.fields.base_price') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('branches.show.stock') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('branches.show.available') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in products" :key="p.product_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ p.name }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-700">{{ p.base_price }}</td>
                                <td class="px-5 py-2 text-end tabular-nums" :class="p.stock_qty !== null && Number(p.stock_qty) <= 0 ? 'text-rose-600 font-semibold' : 'text-slate-700'">
                                    <span v-if="p.stock_mode === 'unit' && p.stock_qty !== null">{{ p.stock_qty }}</span>
                                    <span v-else class="text-slate-400">{{ t('branches.show.not_tracked') }}</span>
                                </td>
                                <td class="px-5 py-2 text-end">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="p.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">
                                        {{ p.is_available ? t('branches.show.available') : t('branches.show.unavailable') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-6 text-center text-sm text-slate-400">{{ t('branches.show.no_products') }}</div>
                </section>

                <!-- Staff -->
                <section v-if="staff" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <Users class="size-4 text-slate-500" />{{ t('branches.show.staff') }}
                    </h2>
                    <ul v-if="staff.length" class="divide-y divide-slate-100">
                        <li v-for="s in staff" :key="s.id" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ s.name }}</p>
                                <p class="text-xs capitalize text-slate-500">{{ humanize(s.position) }}<span v-if="s.phone"> · {{ s.phone }}</span></p>
                            </div>
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="s.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">{{ humanize(s.status) }}</span>
                        </li>
                    </ul>
                    <div v-else class="p-6 text-center text-sm text-slate-400">{{ t('branches.show.no_staff') }}</div>
                </section>

                <!-- Devices (reused endpoint) -->
                <section v-if="devices && devices.length" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-base font-semibold text-slate-950">
                        <MonitorSmartphone class="size-4 text-slate-500" />{{ t('branches.devices.title') }}
                    </h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="d in devices" :key="d.id" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ d.name ?? d.kiosk_id ?? '—' }}</p>
                                <p class="text-xs text-slate-500">{{ humanize(d.device_type) }}<span v-if="d.last_seen_at"> · {{ t('branches.devices.last_seen') }} {{ formatDateTime(d.last_seen_at) }}</span></p>
                            </div>
                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold capitalize text-slate-600">{{ humanize(d.status) }}</span>
                        </li>
                    </ul>
                </section>

                <!-- Activity -->
                <div v-if="activity" class="grid gap-6 lg:grid-cols-3">
                    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                        <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                            <Receipt class="size-4 text-slate-500" />{{ t('branches.show.recent_orders') }}
                        </h2>
                        <ul v-if="activity.recent_orders.length" class="divide-y divide-slate-100">
                            <li
                                v-for="o in activity.recent_orders"
                                :key="o.uuid"
                                class="flex cursor-pointer items-center justify-between gap-3 px-5 py-2.5 transition hover:bg-slate-50"
                                @click="detailUuid = o.uuid"
                            >
                                <div class="min-w-0">
                                    <p class="font-mono text-xs font-semibold text-teal-700">{{ o.uuid.slice(0, 8).toUpperCase() }}</p>
                                    <p class="text-xs text-slate-500">{{ orderTypeLabel(o.order_type) }}<span v-if="o.customer_name"> · {{ o.customer_name }}</span><span v-if="o.staff_name"> · {{ o.staff_name }}</span></p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="orderStatusClass(o.status)">{{ orderStatusLabel(o.status) }}</span>
                                    <span class="font-semibold tabular-nums text-slate-900">{{ o.grand_total }}</span>
                                </div>
                            </li>
                        </ul>
                        <div v-else class="p-6 text-center text-sm text-slate-400">{{ t('branches.show.no_activity') }}</div>
                    </section>

                    <div class="space-y-6">
                        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                                <Clock class="size-4 text-slate-500" />{{ t('branches.show.recent_shifts') }}
                            </h2>
                            <ul v-if="activity.recent_shifts.length" class="divide-y divide-slate-100">
                                <li v-for="s in activity.recent_shifts" :key="s.uuid" class="px-5 py-2.5 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-700">{{ s.staff_name ?? '—' }}</span>
                                        <span class="text-xs capitalize text-slate-500">{{ humanize(s.status) }}</span>
                                    </div>
                                    <p class="text-xs text-slate-400">{{ formatDateTime(s.opened_at) }}</p>
                                </li>
                            </ul>
                            <div v-else class="p-5 text-center text-sm text-slate-400">{{ t('branches.show.no_activity') }}</div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <h2 class="flex items-center gap-2 border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">
                                <Boxes class="size-4 text-slate-500" />{{ t('branches.show.recent_movements') }}
                            </h2>
                            <ul v-if="activity.recent_movements.length" class="divide-y divide-slate-100">
                                <li v-for="(m, i) in activity.recent_movements" :key="i" class="px-5 py-2.5 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-700">{{ m.ingredient_name ?? '—' }}</span>
                                        <span class="font-semibold tabular-nums" :class="Number(m.quantity) < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ m.quantity }} {{ m.unit }}</span>
                                    </div>
                                    <p class="text-xs capitalize text-slate-400">{{ humanize(m.movement_type) }}<span v-if="m.recorded_by"> · {{ t('branches.show.by') }} {{ m.recorded_by }}</span></p>
                                </li>
                            </ul>
                            <div v-else class="p-5 text-center text-sm text-slate-400">{{ t('branches.show.no_activity') }}</div>
                        </section>
                    </div>
                </div>
            </div>
        </div>

        <OrderDetailDrawer v-model:uuid="detailUuid" />
    </MerchantLayout>
</template>

<script setup lang="ts">
/**
 * Sales / Orders -- a paginated, date-filterable list of the merchant's
 * own orders across their branches. reports.view gated. Defaults to today;
 * shows the period's order count + total sales.
 */
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listBranches, type Branch } from '@/lib/api/branches';
import { fetchOrders, type OrderListFilter, type OrderListPayload } from '@/lib/api/reports';
import { ApiError } from '@/lib/api';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import OrderDetailDrawer from './components/OrderDetailDrawer.vue';

// uuid of the order whose detail drawer is open (null = closed).
const detailUuid = ref<string | null>(null);

const { t } = useI18n();

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

const filter = reactive<OrderListFilter>({
    date_from: todayIso(),
    date_to: todayIso(),
    branch_ids: null,
    status: null,
    page: 1,
    per_page: 50,
});
const payload = ref<OrderListPayload | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const branches = ref<Branch[]>([]);

const statusOptions = ['open', 'paid', 'void'] as const;

async function run(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await fetchOrders(filter);
        payload.value = r.data;
    } catch (err) {
        error.value = err instanceof ApiError
            ? (err.status === 403 ? t('orders.forbidden') : `HTTP ${err.status}`)
            : t('orders.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const r = await listBranches();
        branches.value = r.data;
    } catch (err) {
        if (!(err instanceof ApiError)) throw err;
    }
    void run();
});

function goPage(page: number): void {
    filter.page = page;
    void run();
}

function onBranchChange(event: Event): void {
    const v = (event.target as HTMLSelectElement).value;
    filter.branch_ids = v === '' ? null : [Number(v)];
}

function onStatusChange(event: Event): void {
    const v = (event.target as HTMLSelectElement).value;
    filter.status = v === '' ? null : v;
}

function shortId(uuid: string): string {
    return uuid.slice(0, 8).toUpperCase();
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function statusClass(status: string | null): string {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700';
        case 'open': return 'bg-amber-100 text-amber-700';
        case 'void': return 'bg-rose-100 text-rose-700';
        default: return 'bg-slate-100 text-slate-600';
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-teal-600">{{ t('orders.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('orders.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('orders.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.date_from') }}</span>
                    <input type="date" v-model="filter.date_from" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.date_to') }}</span>
                    <input type="date" v-model="filter.date_to" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('reports.filters.branch_label') }}</span>
                    <select :value="filter.branch_ids?.[0] ?? ''" class="w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" @change="onBranchChange">
                        <option value="">{{ t('reports.filters.branch_all') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('orders.filters.status') }}</span>
                    <select :value="filter.status ?? ''" class="w-36 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" @change="onStatusChange">
                        <option value="">{{ t('orders.filters.status_all') }}</option>
                        <option v-for="s in statusOptions" :key="s" :value="s">{{ t(`orders.statuses.${s}`) }}</option>
                    </select>
                </label>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="filter.page = 1; void run()"
                >
                    <Search class="size-4" />
                    {{ loading ? t('reports.filters.running') : t('reports.filters.run') }}
                </button>
            </div>

            <div v-if="payload" class="mb-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.totals.count') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ payload.totals.count }}</p>
                </div>
                <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('orders.totals.grand_total') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-teal-900">{{ payload.totals.grand_total }} <span class="text-sm font-medium">OMR</span></p>
                </div>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div v-if="payload" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="payload.rows.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.time') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.order') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.branch') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.type') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.status') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('orders.columns.items') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.customer') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('orders.columns.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in payload.rows"
                            :key="row.id"
                            class="cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-slate-50"
                            @click="detailUuid = row.uuid"
                        >
                            <td class="px-5 py-2 text-xs tabular-nums text-slate-600">{{ formatDateTime(row.opened_at) }}</td>
                            <!-- P-F8: the printed receipt number when present, else the short uuid. -->
                            <td class="px-5 py-2 font-mono text-xs font-semibold text-teal-700">{{ row.receipt_number ?? shortId(row.uuid) }}</td>
                            <td class="px-5 py-2 text-slate-700">{{ row.branch_name ?? '—' }}</td>
                            <td class="px-5 py-2 text-slate-700">{{ row.order_type ? t(`orders.types.${row.order_type}`) : '—' }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(row.status)">{{ row.status ? t(`orders.statuses.${row.status}`) : '—' }}</span>
                            </td>
                            <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ row.items_count }}</td>
                            <td class="px-5 py-2 text-slate-700">{{ row.customer_name ?? (row.plate_number ?? '—') }}</td>
                            <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.grand_total }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('orders.no_rows') }}</div>

                <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <div>{{ t('orders.pagination', { page: payload.meta.current_page, last: payload.meta.last_page, total: payload.meta.total }) }}</div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="payload.meta.current_page <= 1 || loading" @click="goPage(payload!.meta.current_page - 1)">
                            <ChevronLeft class="size-3.5" /> {{ t('orders.prev') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="payload.meta.current_page >= payload.meta.last_page || loading" @click="goPage(payload!.meta.current_page + 1)">
                            {{ t('orders.next') }} <ChevronRight class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <OrderDetailDrawer v-model:uuid="detailUuid" />
    </MerchantLayout>
</template>

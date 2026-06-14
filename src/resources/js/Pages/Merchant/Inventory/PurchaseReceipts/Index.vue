<script setup lang="ts">
/**
 * PD6 — Goods Received Notes (Saved Purchase Receipts) list.
 *
 * Every saved delivery the merchant recorded: supplier, date, line count, the
 * item vs. charge totals, and the grand total. A row opens the read-only
 * document; "New receipt" opens the full-page Goods Received form. Server gate:
 * inventory.view to read, inventory.manage to record.
 */

import { ClipboardList, Plus } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';
import { ApiError } from '@/lib/api';
import { listPurchaseReceipts, type PurchaseReceipt, type ReceiptPaymentStatus } from '@/lib/api/purchaseReceipts';

const { t, locale } = useI18n();
const router = useRouter();
const { can } = usePermissions();

const canManage = computed(() => can(MerchantPermission.InventoryManage));
const isAr = computed(() => locale.value === 'ar');

const rows = ref<PurchaseReceipt[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

const page = ref(1);
const lastPage = ref(1);
const total = ref(0);

// AP — 'all' shows every receipt; 'outstanding' shows only what's not fully paid.
const filter = ref<'all' | 'outstanding'>('all');

const STATUS_META: Record<ReceiptPaymentStatus, { key: string; cls: string }> = {
    paid: { key: 'purchase_receipts.payment.paid', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    partial: { key: 'purchase_receipts.payment.partial', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    unpaid: { key: 'purchase_receipts.payment.unpaid', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

async function fetchRows(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await listPurchaseReceipts({
            page: page.value,
            payment_status: filter.value === 'outstanding' ? 'outstanding' : undefined,
        });
        rows.value = res.data;
        lastPage.value = res.meta.last_page;
        total.value = res.meta.total;
    } catch (e) {
        loadError.value = e instanceof ApiError ? (e.message || t('purchase_receipts.load_failed')) : t('purchase_receipts.load_failed');
    } finally {
        loading.value = false;
    }
}

function setFilter(next: 'all' | 'outstanding'): void {
    if (filter.value === next) {
        return;
    }
    filter.value = next;
    // Resetting page to 1 fires the page watcher (which fetches); only fetch
    // explicitly when we were already on page 1 so we don't double-request.
    const wasFirstPage = page.value === 1;
    page.value = 1;
    if (wasFirstPage) {
        void fetchRows();
    }
}

onMounted(() => { void fetchRows(); });
watch(page, () => { void fetchRows(); });

function openReceipt(r: PurchaseReceipt): void {
    void router.push({ name: 'merchant.purchase-receipts.show', params: { uuid: r.uuid } });
}

function money(v: string | null | undefined): string {
    const n = Number(v ?? 0);
    return n.toLocaleString(isAr.value ? 'ar' : 'en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleDateString(isAr.value ? 'ar' : 'en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-6xl">
            <div class="flex items-center gap-3">
                <ClipboardList class="size-7 text-teal-600" />
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-slate-900">{{ t('purchase_receipts.title') }}</h1>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t('purchase_receipts.subtitle') }}</p>
                </div>
                <RouterLink
                    v-if="canManage"
                    :to="{ name: 'merchant.purchase-receipts.create' }"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                >
                    <Plus class="size-4" />
                    {{ t('purchase_receipts.new') }}
                </RouterLink>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <!-- AP — All vs Outstanding (unpaid + partial) filter. -->
            <div class="mt-6 inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm shadow-sm">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="filter === 'all' ? 'bg-teal-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
                    @click="setFilter('all')"
                >
                    {{ t('purchase_receipts.payment.filter_all') }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="filter === 'outstanding' ? 'bg-teal-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'"
                    @click="setFilter('outstanding')"
                >
                    {{ t('purchase_receipts.payment.filter_outstanding') }}
                </button>
            </div>

            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="rows.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <ClipboardList class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('purchase_receipts.empty_state') }}</p>
                    <RouterLink
                        v-if="canManage"
                        :to="{ name: 'merchant.purchase-receipts.create' }"
                        class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                    >
                        <Plus class="size-4" />
                        {{ t('purchase_receipts.new') }}
                    </RouterLink>
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.date') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.reference') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.supplier') }}</th>
                            <th class="px-4 py-2.5 text-end font-semibold">{{ t('purchase_receipts.columns.items') }}</th>
                            <th class="px-4 py-2.5 text-end font-semibold">{{ t('purchase_receipts.columns.grand_total') }}</th>
                            <th class="px-4 py-2.5 text-end font-semibold">{{ t('purchase_receipts.columns.balance_due') }}</th>
                            <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.payment_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="row in rows"
                            :key="row.uuid"
                            class="cursor-pointer hover:bg-slate-50/60"
                            @click="openReceipt(row)"
                        >
                            <td class="px-4 py-2.5 tabular-nums text-slate-600">{{ formatDate(row.received_at) }}</td>
                            <td class="px-4 py-2.5 font-medium text-slate-900">{{ row.reference || '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ row.supplier?.name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums text-slate-600">{{ row.lines_count ?? 0 }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums font-semibold text-slate-900">{{ money(row.grand_total) }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums" :class="row.payment_status === 'paid' ? 'text-slate-400' : 'font-semibold text-rose-600'">
                                {{ row.payment_status === 'paid' ? '—' : money(row.balance_due) }}
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset" :class="STATUS_META[row.payment_status].cls">
                                    {{ t(STATUS_META[row.payment_status].key) }}
                                </span>
                                <span v-if="row.is_credit" class="ms-1 text-[11px] text-slate-400">{{ t('purchase_receipts.payment.credit_tag') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="!loading && rows.length > 0" class="mt-3 text-end text-xs text-slate-400">
                {{ t('purchase_receipts.total_count', { count: total }) }}
            </div>

            <!-- Pager -->
            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                    :disabled="page <= 1 || loading"
                    @click="page = page - 1"
                >
                    {{ t('common.previous') }}
                </button>
                <span class="text-sm tabular-nums text-slate-500">{{ page }} / {{ lastPage }}</span>
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                    :disabled="page >= lastPage || loading"
                    @click="page = page + 1"
                >
                    {{ t('common.next') }}
                </button>
            </div>
        </div>
    </MerchantLayout>
</template>

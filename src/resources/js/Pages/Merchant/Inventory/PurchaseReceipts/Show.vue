<script setup lang="ts">
/**
 * PD6 — a saved Goods Received Note, read-only. The frozen document: supplier +
 * date + reference, every line (item, quantity, cost, where it was distributed,
 * which expense category it booked), the named extra charges, and the totals.
 */

import { ArrowLeft, ClipboardList } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError } from '@/lib/api';
import { getPurchaseReceipt, type PurchaseReceipt } from '@/lib/api/purchaseReceipts';

const { t, locale } = useI18n();
const route = useRoute();
const isAr = computed(() => locale.value === 'ar');

const receipt = ref<PurchaseReceipt | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);

function money(v: string | null | undefined): string {
    const n = Number(v ?? 0);
    return n.toLocaleString(isAr.value ? 'ar' : 'en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

function trimQty(q: string | null): string {
    if (q === null) {
        return '—';
    }
    return q.includes('.') ? q.replace(/\.?0+$/, '') : q;
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleDateString(isAr.value ? 'ar' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function categoryLabel(key: string | null): string {
    if (!key) {
        return '—';
    }
    return t(`purchase_receipts.categories.${key}`, key);
}

onMounted(async () => {
    try {
        const res = await getPurchaseReceipt(String(route.params.uuid));
        receipt.value = res.data;
    } catch (e) {
        loadError.value = e instanceof ApiError ? (e.message || t('purchase_receipts.load_failed')) : t('purchase_receipts.load_failed');
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-4xl">
            <RouterLink :to="{ name: 'merchant.purchase-receipts' }" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-slate-700">
                <ArrowLeft class="size-4 rtl:rotate-180" />
                {{ t('purchase_receipts.back') }}
            </RouterLink>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>
            <div v-else-if="loading" class="mt-10 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>

            <template v-else-if="receipt">
                <div class="mt-4 flex items-center gap-3">
                    <ClipboardList class="size-7 text-teal-600" />
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">
                            {{ receipt.reference || t('purchase_receipts.untitled') }}
                        </h1>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ formatDate(receipt.received_at) }}
                            <template v-if="receipt.supplier"> · {{ receipt.supplier.name }}</template>
                            <template v-if="receipt.recorded_by"> · {{ receipt.recorded_by }}</template>
                        </p>
                    </div>
                </div>

                <p v-if="receipt.note" class="mt-4 rounded-lg bg-slate-50 px-4 py-2.5 text-sm text-slate-600">{{ receipt.note }}</p>

                <!-- Lines -->
                <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.item') }}</th>
                                <th class="px-4 py-2.5 text-end font-semibold">{{ t('purchase_receipts.columns.quantity') }}</th>
                                <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.category') }}</th>
                                <th class="px-4 py-2.5 text-start font-semibold">{{ t('purchase_receipts.columns.distribution') }}</th>
                                <th class="px-4 py-2.5 text-end font-semibold">{{ t('purchase_receipts.columns.cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(line, idx) in receipt.lines ?? []" :key="idx">
                                <td class="px-4 py-2.5 font-medium text-slate-900">{{ line.item_name }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-slate-600">{{ trimQty(line.quantity) }} <span class="text-xs text-slate-400">{{ line.unit ?? '' }}</span></td>
                                <td class="px-4 py-2.5 text-slate-600">{{ categoryLabel(line.expense_category) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    <template v-if="line.allocations.length > 0">
                                        <span v-for="(a, i) in line.allocations" :key="i" class="me-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
                                            {{ a.branch_name }}: {{ trimQty(a.quantity) }}
                                        </span>
                                    </template>
                                    <span v-else class="text-xs italic text-slate-400">{{ t('purchase_receipts.kept_central') }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-slate-700">{{ money(line.line_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Charges -->
                <div v-if="(receipt.charges ?? []).length > 0" class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_receipts.form.charges') }}</div>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(charge, idx) in receipt.charges ?? []" :key="idx">
                                <td class="px-4 py-2.5 text-slate-700">{{ charge.name }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ categoryLabel(charge.expense_category) }}</td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-slate-700">{{ money(charge.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Totals -->
                <div class="mt-4 flex flex-col items-end gap-1 text-sm">
                    <div class="flex w-64 justify-between text-slate-500"><span>{{ t('purchase_receipts.form.items_total') }}</span><span class="tabular-nums">{{ money(receipt.items_total) }}</span></div>
                    <div class="flex w-64 justify-between text-slate-500"><span>{{ t('purchase_receipts.form.charges_total') }}</span><span class="tabular-nums">{{ money(receipt.charges_total) }}</span></div>
                    <div class="flex w-64 justify-between border-t border-slate-200 pt-1 font-semibold text-slate-900"><span>{{ t('purchase_receipts.form.grand_total') }}</span><span class="tabular-nums text-teal-700">{{ money(receipt.grand_total) }}</span></div>
                </div>
            </template>
        </div>
    </MerchantLayout>
</template>

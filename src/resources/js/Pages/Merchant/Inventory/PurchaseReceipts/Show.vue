<script setup lang="ts">
/**
 * PD6 — a saved Goods Received Note, read-only. The frozen document: supplier +
 * date + reference, every line (item, quantity, cost, where it was distributed,
 * which expense category it booked), the named extra charges, and the totals.
 */

import { ArrowLeft, ClipboardList, Wallet } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';
import { ApiError } from '@/lib/api';
import { getPurchaseReceipt, recordReceiptPayment, type PurchaseReceipt, type ReceiptPaymentStatus } from '@/lib/api/purchaseReceipts';

const { t, locale } = useI18n();
const route = useRoute();
const { can } = usePermissions();
const isAr = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.InventoryManage));

const receipt = ref<PurchaseReceipt | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);

const STATUS_META: Record<ReceiptPaymentStatus, { key: string; cls: string }> = {
    paid: { key: 'purchase_receipts.payment.paid', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    partial: { key: 'purchase_receipts.payment.partial', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    unpaid: { key: 'purchase_receipts.payment.unpaid', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

// AP — outstanding balance still owed to the supplier.
const balanceDue = computed(() => Number(receipt.value?.balance_due ?? 0));
const hasBalance = computed(() => balanceDue.value > 0.0005);

// Record-payment dialog state.
const showPay = ref(false);
const payAmount = ref('');
const payMethod = ref('');
const payNote = ref('');
const payDate = ref('');
const paySubmitting = ref(false);
const payError = ref<string | null>(null);

function openPay(): void {
    payAmount.value = receipt.value?.balance_due ?? '';
    payMethod.value = '';
    payNote.value = '';
    payDate.value = '';
    payError.value = null;
    showPay.value = true;
}

async function submitPay(): Promise<void> {
    if (receipt.value === null || paySubmitting.value) {
        return;
    }
    const amount = Number(payAmount.value);
    if (!(amount > 0)) {
        payError.value = t('purchase_receipts.payment.amount_required');
        return;
    }
    paySubmitting.value = true;
    payError.value = null;
    try {
        const res = await recordReceiptPayment(receipt.value.uuid, {
            amount: payAmount.value,
            method: payMethod.value.trim() || null,
            note: payNote.value.trim() || null,
            paid_at: payDate.value || null,
        });
        receipt.value = res.data;
        showPay.value = false;
    } catch (e) {
        payError.value = e instanceof ApiError ? (e.message || t('purchase_receipts.payment.record_failed')) : t('purchase_receipts.payment.record_failed');
    } finally {
        paySubmitting.value = false;
    }
}

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

                <!-- AP — payable summary + settlement -->
                <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
                        <Wallet class="size-5 text-teal-600" />
                        <h2 class="flex-1 text-sm font-semibold text-slate-800">{{ t('purchase_receipts.payment.title') }}</h2>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="STATUS_META[receipt.payment_status].cls">
                            {{ t(STATUS_META[receipt.payment_status].key) }}
                        </span>
                        <button
                            v-if="canManage && hasBalance"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
                            @click="openPay"
                        >
                            {{ t('purchase_receipts.payment.record') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-3 divide-x divide-slate-100 rtl:divide-x-reverse">
                        <div class="px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-slate-400">{{ t('purchase_receipts.payment.owed') }}</div>
                            <div class="mt-1 font-semibold tabular-nums text-slate-900">{{ money(receipt.grand_total) }}</div>
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-slate-400">{{ t('purchase_receipts.payment.paid_amount') }}</div>
                            <div class="mt-1 font-semibold tabular-nums text-emerald-700">{{ money(receipt.amount_paid) }}</div>
                        </div>
                        <div class="px-4 py-3">
                            <div class="text-xs uppercase tracking-wide text-slate-400">{{ t('purchase_receipts.columns.balance_due') }}</div>
                            <div class="mt-1 font-semibold tabular-nums" :class="hasBalance ? 'text-rose-600' : 'text-slate-400'">{{ money(receipt.balance_due) }}</div>
                        </div>
                    </div>
                    <div v-if="receipt.due_date" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                        {{ t('purchase_receipts.payment.due_on', { date: formatDate(receipt.due_date) }) }}
                    </div>

                    <!-- Payment history -->
                    <div v-if="(receipt.payments ?? []).length > 0" class="border-t border-slate-100">
                        <div class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_receipts.payment.history') }}</div>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="p in receipt.payments ?? []" :key="p.uuid">
                                    <td class="px-4 py-2 text-slate-600">{{ formatDate(p.paid_at) }}</td>
                                    <td class="px-4 py-2 text-slate-500">
                                        {{ p.method || '—' }}
                                        <span v-if="p.note" class="text-slate-400">· {{ p.note }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-end text-xs text-slate-400">
                                        {{ t('purchase_receipts.payment.balance_after', { amount: money(p.balance_after) }) }}
                                    </td>
                                    <td class="px-4 py-2 text-end font-semibold tabular-nums text-emerald-700">{{ money(p.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- AP — record-payment dialog -->
                <div v-if="showPay" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="showPay = false">
                    <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
                        <h3 class="text-base font-semibold text-slate-900">{{ t('purchase_receipts.payment.record_title') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ t('purchase_receipts.payment.balance_due_hint', { amount: money(receipt.balance_due) }) }}</p>

                        <div v-if="payError" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ payError }}</div>

                        <div class="mt-4 space-y-3">
                            <label class="block">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.amount') }}</span>
                                <input v-model="payAmount" type="number" step="0.001" min="0" inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-400 focus:ring-1 focus:ring-teal-400" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.method') }}</span>
                                <input v-model="payMethod" type="text" maxlength="32" :placeholder="t('purchase_receipts.payment.method_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-400 focus:ring-1 focus:ring-teal-400" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.paid_on') }}</span>
                                <input v-model="payDate" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-400 focus:ring-1 focus:ring-teal-400" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.note') }}</span>
                                <input v-model="payNote" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-400 focus:ring-1 focus:ring-teal-400" />
                            </label>
                        </div>

                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50" @click="showPay = false">{{ t('common.cancel') }}</button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50" :disabled="paySubmitting" @click="submitPay">
                                {{ paySubmitting ? t('common.saving') : t('purchase_receipts.payment.record') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </MerchantLayout>
</template>

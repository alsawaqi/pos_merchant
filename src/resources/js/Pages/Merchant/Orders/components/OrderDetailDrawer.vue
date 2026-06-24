<script setup lang="ts">
/**
 * Order detail slide-over (v2 #2).
 *
 * Given an order uuid, fetches GET /api/orders/{uuid} and renders the
 * full order: header (branch/staff/customer/plate/note), line items
 * with add-ons + per-line discounts, order-level discounts in effect
 * (#4), payments (incl. card auth code) and loyalty points moved.
 *
 * v-model:uuid contract — parent sets the uuid to open, the drawer
 * clears it (emits null) on close.
 */

import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';
import { fetchOrderDetail, type OrderDetailPayload } from '@/lib/api/reports';
import { ApiError } from '@/lib/api';

const props = defineProps<{ uuid: string | null }>();
const emit = defineEmits<{ (e: 'update:uuid', value: string | null): void }>();

const { t } = useI18n();

/**
 * P-F5 — raw payment.method → translated label ('bank_pos' → "Bank
 * POS"); unknown methods fall back to plain capitalisation.
 */
function methodLabel(method: string | null | undefined): string {
    if (!method) return '—';
    const key = `orders.payment_methods.${method}`;
    const label = t(key);
    return label !== key ? label : method.charAt(0).toUpperCase() + method.slice(1).replace(/_/g, ' ');
}

const detail = ref<OrderDetailPayload | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(uuid: string): Promise<void> {
    loading.value = true;
    error.value = null;
    detail.value = null;
    try {
        const r = await fetchOrderDetail(uuid);
        detail.value = r.data;
    } catch (err) {
        error.value = err instanceof ApiError ? `HTTP ${err.status}` : t('orders.detail.load_failed');
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.uuid,
    (uuid) => {
        if (uuid) void load(uuid);
    },
    { immediate: true },
);

function close(): void {
    emit('update:uuid', null);
}

function shortId(uuid: string): string {
    return uuid.slice(0, 8).toUpperCase();
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
    return label !== key ? label : type.replace(/_/g, ' ');
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    const key = `orders.statuses.${status}`;
    const label = t(key);
    return label !== key ? label : status;
}

// Commission/payout lifecycle chip: pending → reconciled → in_payout → paid.
function commissionStatusClass(status: string): string {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700';
        case 'in_payout': return 'bg-indigo-100 text-indigo-700';
        case 'reconciled': return 'bg-sky-100 text-sky-700';
        case 'pending': return 'bg-amber-100 text-amber-700';
        default: return 'bg-slate-100 text-slate-500'; // none
    }
}
</script>

<template>
    <div v-if="uuid" class="fixed inset-0 z-50 flex justify-end">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-slate-900/40" @click="close" />

        <!-- Panel -->
        <aside class="relative flex h-full w-full max-w-xl flex-col overflow-y-auto bg-slate-50 shadow-2xl">
            <header class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-slate-950">{{ t('orders.detail.title') }}</h2>
                    <!-- P-F8: the printed receipt number when present, else the short uuid. -->
                    <p v-if="detail" class="font-mono text-xs font-semibold text-slate-500">{{ detail.order.receipt_number ?? shortId(detail.order.uuid) }}</p>
                </div>
                <button type="button" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" @click="close">
                    <X class="size-5" />
                </button>
            </header>

            <div v-if="loading" class="p-8 text-center text-sm text-slate-500">{{ t('orders.detail.loading') }}</div>
            <div v-else-if="error" class="m-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div v-else-if="detail" class="space-y-4 p-5">
                <!-- Header card -->
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-slate-500">{{ t('orders.columns.status') }}</dt>
                        <dd class="text-end font-semibold text-slate-900">{{ statusLabel(detail.order.status) }}</dd>
                        <dt class="text-slate-500">{{ t('orders.columns.branch') }}</dt>
                        <dd class="text-end font-semibold text-slate-900">{{ detail.order.branch?.name ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('orders.columns.type') }}</dt>
                        <dd class="text-end text-slate-900">{{ orderTypeLabel(detail.order.order_type) }}</dd>
                        <dt class="text-slate-500">{{ t('orders.detail.served_by') }}</dt>
                        <dd class="text-end text-slate-900">{{ detail.order.staff?.name ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('orders.detail.customer') }}</dt>
                        <dd class="text-end text-slate-900">
                            {{ detail.order.customer?.name ?? '—' }}
                            <span v-if="detail.order.customer?.phone" class="block text-xs text-slate-400">{{ detail.order.customer.phone }}</span>
                        </dd>
                        <template v-if="detail.order.plate_number">
                            <dt class="text-slate-500">{{ t('orders.detail.vehicle') }}</dt>
                            <dd class="text-end font-mono text-slate-900">{{ detail.order.plate_number }}</dd>
                        </template>
                        <dt class="text-slate-500">{{ t('orders.columns.time') }}</dt>
                        <dd class="text-end text-xs tabular-nums text-slate-600">{{ formatDateTime(detail.order.opened_at) }}</dd>
                        <template v-if="detail.order.note">
                            <dt class="text-slate-500">{{ t('orders.detail.note') }}</dt>
                            <dd class="text-end text-slate-700">{{ detail.order.note }}</dd>
                        </template>
                    </dl>
                </section>

                <!-- Items -->
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.detail.items') }}</h3>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="item in detail.items" :key="item.id" class="py-2.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900">
                                        <span class="tabular-nums text-slate-500">{{ item.qty }}×</span> {{ item.product_name }}
                                    </p>
                                    <ul v-if="item.addons.length" class="mt-0.5 ps-4 text-xs text-slate-500">
                                        <li v-for="(a, i) in item.addons" :key="i">+ {{ a.name }} <span class="tabular-nums">({{ a.price_delta }})</span></li>
                                    </ul>
                                    <p v-for="(d, i) in item.discounts" :key="i" class="mt-0.5 text-xs font-medium text-rose-600">
                                        − {{ d.name }} <span class="tabular-nums">({{ d.amount }})</span>
                                    </p>
                                    <p v-if="item.notes" class="mt-0.5 text-xs italic text-slate-400">{{ item.notes }}</p>
                                </div>
                                <div class="shrink-0 text-end">
                                    <p class="font-semibold tabular-nums text-slate-900">{{ item.line_total }}</p>
                                    <p v-if="Number(item.line_discount) > 0" class="text-xs tabular-nums text-rose-500">
                                        −{{ item.line_discount }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Order-level discounts (#4) -->
                <section v-if="detail.order_discounts.length" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.detail.order_discounts') }}</h3>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="(d, i) in detail.order_discounts" :key="i" class="flex items-center justify-between">
                            <span class="text-slate-700">{{ d.name }}</span>
                            <span class="font-semibold tabular-nums text-rose-600">−{{ d.amount }}</span>
                        </li>
                    </ul>
                </section>

                <!-- Totals -->
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.subtotal') }}</dt><dd class="tabular-nums text-slate-900">{{ detail.order.totals.subtotal }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.discount') }}</dt><dd class="tabular-nums text-rose-600">−{{ detail.order.totals.discount_total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.tax') }}</dt><dd class="tabular-nums text-slate-900">{{ detail.order.totals.tax_total }}</dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 text-base font-bold">
                            <dt class="text-slate-900">{{ t('orders.detail.grand_total') }}</dt>
                            <dd class="tabular-nums text-slate-950">{{ detail.order.totals.grand_total }} <span class="text-xs font-medium text-slate-400">OMR</span></dd>
                        </div>
                    </dl>
                </section>

                <!-- Commission / payout (settled-aware; final once paid) -->
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.detail.commission') }}</h3>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="commissionStatusClass(detail.commission.commission_status)">
                            {{ t(`orders.commission.status.${detail.commission.commission_status}`) }}
                        </span>
                    </div>
                    <dl v-if="detail.commission.commission_status !== 'none'" class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.gross') }}</dt><dd class="tabular-nums text-slate-900">{{ detail.order.totals.grand_total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.admin_commission') }}</dt><dd class="tabular-nums text-rose-600">−{{ detail.commission.admin_commission }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ t('orders.detail.bank_commission') }}</dt><dd class="tabular-nums text-rose-600">−{{ detail.commission.bank_commission }}</dd></div>
                        <div class="flex justify-between border-t border-slate-100 pt-1.5"><dt class="text-slate-500">{{ t('orders.detail.total_deducted') }}</dt><dd class="tabular-nums text-rose-700">−{{ detail.commission.total_commission }}</dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-1.5 text-base font-bold">
                            <dt class="text-slate-900">{{ t('orders.detail.merchant_net') }}</dt>
                            <dd class="tabular-nums text-teal-700">{{ detail.commission.merchant_net }} <span class="text-xs font-medium text-slate-400">OMR</span></dd>
                        </div>
                        <p v-if="detail.commission.payout_date" class="pt-1 text-xs text-emerald-700">
                            {{ t('orders.commission.paid_on', { date: formatDateTime(detail.commission.payout_date) }) }}
                        </p>
                        <p v-else class="pt-1 text-xs text-slate-400">{{ t(`orders.commission.note.${detail.commission.commission_status}`) }}</p>
                    </dl>
                    <p v-else class="text-sm text-slate-400">{{ t('orders.commission.note.none') }}</p>
                </section>

                <!-- Payments -->
                <section v-if="detail.payments.length" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.detail.payments') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(p, i) in detail.payments" :key="i" class="flex items-center justify-between">
                            <div>
                                <span class="font-medium text-slate-900">{{ methodLabel(p.method) }}</span>
                                <span v-if="p.softpos_auth_code" class="block text-xs text-slate-400">{{ t('orders.detail.auth_code') }}: {{ p.softpos_auth_code }}</span>
                            </div>
                            <span class="font-semibold tabular-nums text-slate-900">{{ p.amount }}</span>
                        </li>
                    </ul>
                </section>

                <!-- Loyalty (#2 points gained) -->
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.detail.loyalty') }}</h3>
                    <div
                        v-if="detail.loyalty.points_earned || detail.loyalty.points_redeemed || detail.loyalty.stamps_earned || detail.loyalty.stamps_redeemed"
                        class="grid grid-cols-2 gap-3 text-sm"
                    >
                        <div class="rounded-lg bg-emerald-50 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ t('orders.detail.points_earned') }}</p>
                            <p class="mt-1 text-lg font-bold tabular-nums text-emerald-900">+{{ detail.loyalty.points_earned }}</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ t('orders.detail.points_redeemed') }}</p>
                            <p class="mt-1 text-lg font-bold tabular-nums text-amber-900">−{{ detail.loyalty.points_redeemed }}</p>
                        </div>
                        <div v-if="detail.loyalty.stamps_earned" class="rounded-lg bg-emerald-50 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ t('orders.detail.stamps_earned') }}</p>
                            <p class="mt-1 text-lg font-bold tabular-nums text-emerald-900">+{{ detail.loyalty.stamps_earned }}</p>
                        </div>
                        <div v-if="detail.loyalty.stamps_redeemed" class="rounded-lg bg-amber-50 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ t('orders.detail.stamps_redeemed') }}</p>
                            <p class="mt-1 text-lg font-bold tabular-nums text-amber-900">−{{ detail.loyalty.stamps_redeemed }}</p>
                        </div>
                    </div>
                    <p v-else class="text-sm text-slate-400">{{ t('orders.detail.no_loyalty') }}</p>
                </section>
            </div>
        </aside>
    </div>
</template>

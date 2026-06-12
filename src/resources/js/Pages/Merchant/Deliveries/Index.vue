<script setup lang="ts">
/**
 * Deliveries (P-G7) — provider-statement reconciliation.
 *
 * Pending tab: no-tender delivery orders grouped by provider, punched vs
 * expected payout, bulk-select → "Confirm selected" (statement matched) or
 * per-row Adjust (provider paid a different amount; variance recorded).
 * Confirmed tab: settlement history with received amounts + variances.
 *
 * Only confirmation turns an order into revenue — pending rows are visible
 * everywhere but counted nowhere. deliveries.manage-gated server-side; F5
 * branch scope shrinks the lists.
 */

import { Bike, CheckCheck } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import Pagination from '@/Components/Pagination.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError } from '@/lib/api';

/** The server's actual error reason (e.g. the 422 texts from the confirm
 *  action or a BranchScope 403) — ApiError.message is only the generic
 *  "Request failed with status N". */
function apiMessage(e: unknown, fallback: string): string {
    if (e instanceof ApiError) {
        const payload = e.payload as { message?: string } | null;
        return payload?.message ?? fallback;
    }
    return fallback;
}
import {
    adjustDelivery,
    confirmDeliveries,
    listDeliveries,
    type DeliveriesPage,
    type DeliveryOrder,
} from '@/lib/api/deliveries';

const { t } = useI18n();

type TabKey = 'pending' | 'confirmed';
const activeTab = ref<TabKey>('pending');

const error = ref<string | null>(null);
const success = ref<string | null>(null);
const loading = ref(true);
const busy = ref(false);

const emptyMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };
const pending = ref<DeliveriesPage | null>(null);
const confirmed = ref<DeliveriesPage | null>(null);

const selected = ref<Set<number>>(new Set());

// ---- Adjust modal ---------------------------------------------------
const adjustTarget = ref<DeliveryOrder | null>(null);
const adjustAmount = ref('');
const adjustBusy = ref(false);
const adjustError = ref<string | null>(null);

async function fetchPending(page = 1): Promise<void> {
    loading.value = true;
    try {
        pending.value = await listDeliveries('pending', page);
        selected.value = new Set();
    } catch (e) {
        error.value = apiMessage(e, t('deliveries.load_failed'));
    } finally {
        loading.value = false;
    }
}

async function fetchConfirmed(page = 1): Promise<void> {
    try {
        confirmed.value = await listDeliveries('confirmed', page);
    } catch (e) {
        error.value = apiMessage(e, t('deliveries.load_failed'));
    }
}

onMounted(() => {
    void fetchPending();
    void fetchConfirmed();
});

/** Pending rows grouped by provider for per-statement review. */
const pendingGroups = computed(() => {
    const groups = new Map<string, DeliveryOrder[]>();
    for (const row of pending.value?.data ?? []) {
        const key = row.provider_name ?? '—';
        (groups.get(key) ?? groups.set(key, []).get(key)!).push(row);
    }
    return [...groups.entries()].map(([provider, rows]) => ({ provider, rows }));
});

function toggle(id: number): void {
    const next = new Set(selected.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selected.value = next;
}

function toggleGroup(rows: DeliveryOrder[]): void {
    const next = new Set(selected.value);
    const allIn = rows.every((r) => next.has(r.id));
    for (const r of rows) {
        if (allIn) {
            next.delete(r.id);
        } else {
            next.add(r.id);
        }
    }
    selected.value = next;
}

async function confirmSelected(): Promise<void> {
    if (selected.value.size === 0 || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        const result = await confirmDeliveries([...selected.value]);
        success.value = t('deliveries.confirmed_ok', { n: result.data.orders_confirmed });
        await Promise.all([fetchPending(), fetchConfirmed()]);
    } catch (e) {
        error.value = apiMessage(e, t('deliveries.action_failed'));
    } finally {
        busy.value = false;
    }
}

function openAdjust(row: DeliveryOrder): void {
    adjustTarget.value = row;
    adjustAmount.value = row.expected_payout;
    adjustError.value = null;
}

async function submitAdjust(): Promise<void> {
    const target = adjustTarget.value;
    if (target === null || adjustBusy.value) return;
    adjustBusy.value = true;
    adjustError.value = null;
    try {
        await adjustDelivery(target.uuid, adjustAmount.value);
        adjustTarget.value = null;
        success.value = t('deliveries.adjusted_ok');
        await Promise.all([fetchPending(), fetchConfirmed()]);
    } catch (e) {
        adjustError.value = apiMessage(e, t('deliveries.action_failed'));
    } finally {
        adjustBusy.value = false;
    }
}

function fmtDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function varianceClass(value: string | null): string {
    const n = Number(value ?? 0);
    if (n > 0) return 'text-emerald-600';
    if (n < 0) return 'text-rose-600';
    return 'text-slate-500';
}
</script>

<template>
    <MerchantLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950">
                        <Bike class="size-6 text-teal-600" /> {{ t('deliveries.title') }}
                    </h1>
                    <p class="text-sm text-slate-500">{{ t('deliveries.subtitle') }}</p>
                </div>
                <button
                    v-if="activeTab === 'pending'"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 disabled:opacity-50"
                    :disabled="busy || selected.size === 0"
                    @click="confirmSelected"
                >
                    <CheckCheck class="size-4" />
                    {{ t('deliveries.confirm_selected', { n: selected.size }) }}
                </button>
            </div>

            <p v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</p>
            <p v-if="success" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ success }}</p>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="tab in ([['pending', t('deliveries.tabs.pending')], ['confirmed', t('deliveries.tabs.confirmed')]] as [TabKey, string][])"
                    :key="tab[0]"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === tab[0] ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                    @click="activeTab = tab[0]"
                >{{ tab[1] }}</button>
            </div>

            <!-- Totals banner -->
            <div v-if="activeTab === 'pending' && pending" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('deliveries.totals.pending_count') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-950 tabular-nums">{{ pending.totals.count }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('deliveries.totals.punched') }}</p>
                    <p class="mt-1 text-xl font-bold text-slate-950 tabular-nums">{{ pending.totals.punched_total }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('deliveries.totals.expected') }}</p>
                    <p class="mt-1 text-xl font-bold text-teal-700 tabular-nums">{{ pending.totals.expected_total }}</p>
                </div>
            </div>

            <!-- Pending: grouped by provider -->
            <template v-if="activeTab === 'pending'">
                <p v-if="loading" class="px-1 py-6 text-sm text-slate-500">{{ t('deliveries.loading') }}</p>
                <div v-else-if="pendingGroups.length === 0" class="rounded-2xl border border-slate-200 bg-white px-5 py-10 text-center text-sm text-slate-400">
                    {{ t('deliveries.empty_pending') }}
                </div>
                <div v-for="group in pendingGroups" v-else :key="group.provider" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-5 py-3">
                        <p class="text-sm font-bold text-slate-900">{{ group.provider }}</p>
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                                :checked="group.rows.every((r) => selected.has(r.id))"
                                @change="toggleGroup(group.rows)"
                            >
                            {{ t('deliveries.select_all') }}
                        </label>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-start text-xs uppercase tracking-wide text-slate-500">
                                <th class="w-10 px-5 py-2"></th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.reference') }}</th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.branch') }}</th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.punched_at') }}</th>
                                <th class="px-2 py-2 text-end">{{ t('deliveries.table.punched') }}</th>
                                <th class="px-2 py-2 text-end">{{ t('deliveries.table.commission') }}</th>
                                <th class="px-2 py-2 text-end">{{ t('deliveries.table.expected') }}</th>
                                <th class="w-24 px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in group.rows" :key="row.uuid" class="border-b border-slate-50 hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <input type="checkbox" class="size-4 rounded border-slate-300" :checked="selected.has(row.id)" @change="toggle(row.id)">
                                </td>
                                <td class="px-2 py-3 font-semibold text-slate-900">{{ row.reference ?? row.receipt_number ?? row.uuid.slice(0, 8) }}</td>
                                <td class="px-2 py-3 text-slate-600">{{ row.branch_name ?? '—' }}</td>
                                <td class="px-2 py-3 text-slate-600">{{ fmtDate(row.punched_at) }}</td>
                                <td class="px-2 py-3 text-end tabular-nums">{{ row.grand_total }}</td>
                                <td class="px-2 py-3 text-end tabular-nums text-slate-500">{{ row.commission_percent }}%</td>
                                <td class="px-2 py-3 text-end font-semibold tabular-nums text-teal-700">{{ row.expected_payout }}</td>
                                <td class="px-5 py-3 text-end">
                                    <button type="button" class="rounded border border-amber-200 px-2 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-50" @click="openAdjust(row)">
                                        {{ t('deliveries.adjust') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination
                    v-if="pending && pending.last_page > 1"
                    :meta="pending ?? { ...emptyMeta }"
                    :loading="loading"
                    @update:page="fetchPending($event)"
                />
            </template>

            <!-- Confirmed history -->
            <template v-else>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                                <th class="px-5 py-2 text-start">{{ t('deliveries.table.provider') }}</th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.reference') }}</th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.branch') }}</th>
                                <th class="px-2 py-2 text-start">{{ t('deliveries.table.confirmed_at') }}</th>
                                <th class="px-2 py-2 text-end">{{ t('deliveries.table.expected') }}</th>
                                <th class="px-2 py-2 text-end">{{ t('deliveries.table.received') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('deliveries.table.variance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in confirmed?.data ?? []" :key="row.uuid" class="border-b border-slate-50">
                                <td class="px-5 py-3 font-semibold text-slate-900">{{ row.provider_name ?? '—' }}</td>
                                <td class="px-2 py-3 text-slate-600">{{ row.reference ?? '—' }}</td>
                                <td class="px-2 py-3 text-slate-600">{{ row.branch_name ?? '—' }}</td>
                                <td class="px-2 py-3 text-slate-600">{{ fmtDate(row.confirmed_at) }}</td>
                                <td class="px-2 py-3 text-end tabular-nums">{{ row.expected_payout }}</td>
                                <td class="px-2 py-3 text-end font-semibold tabular-nums">{{ row.received_amount ?? '—' }}</td>
                                <td class="px-5 py-3 text-end font-semibold tabular-nums" :class="varianceClass(row.variance)">{{ row.variance ?? '—' }}</td>
                            </tr>
                            <tr v-if="(confirmed?.data ?? []).length === 0">
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">{{ t('deliveries.empty_confirmed') }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="confirmed && confirmed.last_page > 1" class="border-t border-slate-100 px-5 py-3">
                        <Pagination :meta="confirmed" @update:page="fetchConfirmed($event)" />
                    </div>
                </div>
            </template>
        </div>

        <!-- Adjust modal -->
        <BaseModal v-if="adjustTarget" :title="t('deliveries.adjust_title')" @close="adjustTarget = null">
            <form class="space-y-4" @submit.prevent="submitAdjust">
                <p class="text-xs text-slate-500">{{ t('deliveries.adjust_hint') }}</p>
                <p v-if="adjustError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ adjustError }}</p>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-slate-50 px-3 py-2">
                        <p class="text-xs text-slate-500">{{ t('deliveries.table.punched') }}</p>
                        <p class="font-semibold tabular-nums">{{ adjustTarget.grand_total }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-3 py-2">
                        <p class="text-xs text-slate-500">{{ t('deliveries.table.expected') }}</p>
                        <p class="font-semibold tabular-nums">{{ adjustTarget.expected_payout }}</p>
                    </div>
                </div>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('deliveries.received_label') }}
                    <input
                        v-model="adjustAmount"
                        type="number"
                        step="0.001"
                        min="0"
                        required
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
                    >
                </label>
                <button type="submit" :disabled="adjustBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    {{ t('deliveries.adjust_submit') }}
                </button>
            </form>
        </BaseModal>
    </MerchantLayout>
</template>

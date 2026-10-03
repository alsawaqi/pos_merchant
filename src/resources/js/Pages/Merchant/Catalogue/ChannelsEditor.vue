<script setup lang="ts">
/**
 * LAUNCH-P4 B3 — where a product or combo is sold (owner decision 8):
 *   - In store (till + handheld);
 *   - QR menu (at the in-store price);
 *   - Delivery, with the delivery price and, per provider, a "listed" tick
 *     and an optional own price (blank = the delivery price, else the base
 *     price);
 *   - the branch rule (H6: every branch, or only the selected ones). HQ users
 *     only — branch-limited users switch their own branches with "Sold out".
 * Shared by the product wizard and the combo editor. Never sends shelf
 * counts (H7).
 */
import { Building2, QrCode, Store, Truck } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { effectiveProviderPrice, type ProviderChannelRow } from '@/lib/channels';
import type { DeliveryProvider } from '@/lib/api/deliveryProviders';
import type { Branch as BranchLite } from '@/lib/api/branches';

const props = defineProps<{
    providers: DeliveryProvider[];
    branches: BranchLite[];
    basePrice: string;
    /** HQ users only (the branch rule spans every branch). */
    canEditBranches: boolean;
    disabled?: boolean;
    deliveryPriceError?: string | null;
    providersError?: string | null;
    branchesError?: string | null;
}>();

const soldInStore = defineModel<boolean>('soldInStore', { required: true });
const showOnQr = defineModel<boolean>('showOnQr', { required: true });
const soldOnDelivery = defineModel<boolean>('soldOnDelivery', { required: true });
const deliveryPrice = defineModel<string>('deliveryPrice', { required: true });
const providerRows = defineModel<Record<string, ProviderChannelRow>>('providerRows', { required: true });
const branchScope = defineModel<'all' | 'selected'>('branchScope', { required: true });
const branchIds = defineModel<number[]>('branchIds', { required: true });

const emit = defineEmits<{ (e: 'provider-touched', uuid: string): void }>();

const { t } = useI18n();

function row(uuid: string): ProviderChannelRow {
    return providerRows.value[uuid] ?? { listed: true, price: '' };
}

function setListed(uuid: string, listed: boolean): void {
    providerRows.value = { ...providerRows.value, [uuid]: { ...row(uuid), listed } };
    emit('provider-touched', uuid);
}

function setPrice(uuid: string, price: string): void {
    providerRows.value = { ...providerRows.value, [uuid]: { ...row(uuid), price } };
    emit('provider-touched', uuid);
}

function toggleBranch(id: number, on: boolean): void {
    const set = new Set(branchIds.value);
    if (on) set.add(id);
    else set.delete(id);
    branchIds.value = [...set];
}

/** A blank provider price falls back to the delivery price, else the base price. */
function defaultProviderPrice(): string {
    return effectiveProviderPrice(undefined, String(deliveryPrice.value ?? ''), props.basePrice) || '0.000';
}
</script>

<template>
    <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="channels">
        <div>
            <h2 class="text-sm font-semibold text-slate-900">{{ t('channels.title') }}</h2>
            <p class="mt-0.5 text-xs text-slate-500">{{ t('channels.hint') }}</p>
        </div>

        <fieldset :disabled="disabled" class="min-w-0 space-y-4">
            <!-- In store -->
            <div class="rounded-xl border border-slate-200 p-3">
                <label class="flex items-start gap-2 text-sm font-semibold text-slate-800">
                    <input v-model="soldInStore" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="channel-in-store">
                    <span>
                        <Store class="me-1 inline size-4 text-teal-600" />
                        {{ t('channels.in_store') }}
                        <span class="mt-0.5 block text-xs font-normal text-slate-500">{{ t('channels.in_store_hint') }}</span>
                    </span>
                </label>
            </div>

            <!-- QR menu -->
            <div class="rounded-xl border border-slate-200 p-3">
                <label class="flex items-start gap-2 text-sm font-semibold text-slate-800">
                    <input v-model="showOnQr" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="channel-qr">
                    <span>
                        <QrCode class="me-1 inline size-4 text-indigo-600" />
                        {{ t('channels.qr') }}
                        <span class="mt-0.5 block text-xs font-normal text-slate-500">{{ t('channels.qr_hint') }}</span>
                    </span>
                </label>
            </div>

            <!-- Delivery + providers -->
            <div class="rounded-xl border border-slate-200 p-3">
                <label class="flex items-start gap-2 text-sm font-semibold text-slate-800">
                    <input v-model="soldOnDelivery" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="channel-delivery">
                    <span>
                        <Truck class="me-1 inline size-4 text-amber-600" />
                        {{ t('channels.delivery') }}
                        <span class="mt-0.5 block text-xs font-normal text-slate-500">{{ t('channels.delivery_hint') }}</span>
                    </span>
                </label>
                <div v-if="soldOnDelivery" class="mt-3 space-y-3 ps-6">
                    <label class="block max-w-xs">
                        <span class="text-xs font-medium text-slate-700">{{ t('catalogue.fields.delivery_price') }} (OMR)</span>
                        <input v-model="deliveryPrice" type="number" step="0.001" min="0" :placeholder="basePrice || '—'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="delivery-price">
                        <span class="mt-1 block text-xs text-slate-500">{{ t('catalogue.fields.delivery_price_hint') }}</span>
                        <span v-if="deliveryPriceError" class="mt-1 block text-xs text-rose-600">{{ deliveryPriceError }}</span>
                    </label>
                    <div v-if="providers.length > 0">
                        <p class="text-xs font-semibold text-slate-700">{{ t('channels.providers') }}</p>
                        <p class="text-xs text-slate-500">{{ t('channels.providers_hint') }}</p>
                        <p v-if="providersError" class="mt-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ providersError }}</p>
                        <ul class="mt-2 space-y-1.5">
                            <li v-for="provider in providers" :key="provider.uuid" class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 px-3 py-2" data-test="provider-row">
                                <label class="flex min-w-[9rem] items-center gap-2 text-sm font-medium text-slate-700">
                                    <input
                                        type="checkbox"
                                        :checked="row(provider.uuid).listed"
                                        class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                                        data-test="provider-listed"
                                        @change="setListed(provider.uuid, ($event.target as HTMLInputElement).checked)"
                                    >
                                    <span v-if="provider.color" class="inline-block size-3 rounded-full border border-slate-200" :style="{ backgroundColor: provider.color }"></span>
                                    {{ provider.name }}
                                </label>
                                <input
                                    v-if="row(provider.uuid).listed"
                                    :value="row(provider.uuid).price"
                                    type="text"
                                    inputmode="decimal"
                                    :placeholder="defaultProviderPrice()"
                                    class="w-32 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-mono text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    data-test="provider-price"
                                    @input="setPrice(provider.uuid, ($event.target as HTMLInputElement).value)"
                                >
                                <span v-else class="text-xs italic text-slate-400">{{ t('channels.not_listed') }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Branches (H6) — HQ users only -->
            <div v-if="canEditBranches" class="rounded-xl border border-slate-200 p-3" data-test="branch-scope">
                <p class="text-sm font-semibold text-slate-800">
                    <Building2 class="me-1 inline size-4 text-teal-600" />
                    {{ t('channels.branches') }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">{{ t('channels.branches_hint') }}</p>
                <div class="mt-2 flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input v-model="branchScope" type="radio" value="all" class="border-slate-300 text-teal-600 focus:ring-teal-500" data-test="scope-all">
                        {{ t('channels.scope_all') }}
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="branchScope" type="radio" value="selected" class="border-slate-300 text-teal-600 focus:ring-teal-500" data-test="scope-selected">
                        {{ t('channels.scope_selected') }}
                    </label>
                </div>
                <div v-if="branchScope === 'selected'" class="mt-2 grid gap-1.5 sm:grid-cols-2">
                    <p v-if="branches.length === 0" class="text-xs italic text-slate-500">{{ t('catalogue.branches.no_branches') }}</p>
                    <label v-for="b in branches" :key="b.id" class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700">
                        <input
                            type="checkbox"
                            :checked="branchIds.includes(b.id)"
                            class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200"
                            data-test="scope-branch"
                            @change="toggleBranch(b.id, ($event.target as HTMLInputElement).checked)"
                        >
                        <span class="truncate">{{ b.name }}</span>
                    </label>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ t('channels.no_stock_hint') }}</p>
                <p v-if="branchesError" class="mt-1 text-xs text-rose-600">{{ branchesError }}</p>
            </div>
        </fieldset>
    </section>
</template>

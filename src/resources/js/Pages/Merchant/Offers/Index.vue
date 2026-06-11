<script setup lang="ts">
/**
 * Offers — P-F9.
 *
 * Merchant promotions the POS device evaluates by itself: each offer is
 * a TYPE (bogo / bundle / multi_buy / cheapest_free / spend_get) plus a
 * type-specific config. The create/edit modal switches its form section
 * on a type picker; the shared section (validity, weekdays, time window,
 * branch scope, max per order, auto-apply) mirrors the Discounts page.
 *
 * Money is entered in OMR and converted to integer BAISAS in the config
 * payload (the device wire convention).
 *
 * Permission gates (offers share the discounts keys):
 *   - Page reachable when DiscountsView
 *   - Add / Edit / Pause / Resume / Delete only when DiscountsManage
 */

import { BadgePercent, Calendar, Pause, Pencil, Plus, PlayCircle, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createOffer,
    deleteOffer,
    listOffers,
    pauseOffer,
    resumeOffer,
    updateOffer,
    type BogoConfig,
    type BundleConfig,
    type CheapestFreeConfig,
    type CreateOfferPayload,
    type MultiBuyConfig,
    type Offer,
    type OfferConfig,
    type OfferType,
    type SpendGetConfig,
    type SpendGetRewardType,
} from '@/lib/api/offers';
import { listBranches, type Branch } from '@/lib/api/branches';
import { listCategories, listProducts, type Category, type Product } from '@/lib/api/catalogue';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

const offers = ref<Offer[]>([]);
const products = ref<Product[]>([]);
const categories = ref<Category[]>([]);
const branches = ref<Branch[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');
const filteredOffers = computed<Offer[]>(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') return offers.value;
    return offers.value.filter((o) =>
        o.name.toLowerCase().includes(term)
        || t(`offers.types.${o.type}`).toLowerCase().includes(term),
    );
});

const canManage = computed(() => can(MerchantPermission.DiscountsManage));

async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const [o, p, c, b] = await Promise.all([
            listOffers(),
            listProducts({ per_page: 200 }),
            listCategories(),
            listBranches(),
        ]);
        offers.value = o.data;
        products.value = p.data;
        categories.value = c.data;
        branches.value = b.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load offers';
    } finally {
        loading.value = false;
    }
}

onMounted(() => { void fetchAll(); });

// ---- Money helpers (OMR string <-> integer baisas) ----------------
function toBaisas(omr: string): number {
    const n = Number.parseFloat(omr);
    return Number.isFinite(n) ? Math.round(n * 1000) : 0;
}

function toOmr(baisas: number): string {
    return (baisas / 1000).toFixed(3);
}

// ---- Modal -------------------------------------------------------
type ModalMode = 'create' | 'edit';
const modalOpen = ref(false);
const modalMode = ref<ModalMode>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalTarget = ref<Offer | null>(null);

interface BundleGroupForm {
    label: string;
    label_ar: string;
    product_ids: number[];
    qty: number;
}

interface FormShape {
    name: string;
    name_ar: string;
    type: OfferType;
    // bogo
    buy_product_ids: number[];
    buy_category_ids: number[];
    buy_qty: number;
    get_same_as_buy: boolean;
    get_product_ids: number[];
    get_category_ids: number[];
    get_qty: number;
    get_percent_off: number;
    // bundle
    bundle_price_omr: string;
    bundle_groups: BundleGroupForm[];
    // multi_buy
    mb_product_ids: number[];
    mb_category_ids: number[];
    mb_qty: number;
    mb_price_omr: string;
    // cheapest_free
    cf_product_ids: number[];
    cf_category_ids: number[];
    cf_qty: number;
    cf_free_count: number;
    // spend_get
    sg_min_subtotal_omr: string;
    sg_reward_type: SpendGetRewardType;
    sg_percent: number;
    sg_fixed_omr: string;
    sg_reward_product_id: number | null;
    // shared
    auto_apply: boolean;
    validity_start: string;
    validity_end: string;
    dayofweek_mask: number;
    time_start: string;
    time_end: string;
    all_branches: boolean;
    branch_ids: number[];
    max_per_order: string;
}

const form = reactive<FormShape>(defaultForm());

function defaultForm(): FormShape {
    return {
        name: '',
        name_ar: '',
        type: 'bogo',
        buy_product_ids: [],
        buy_category_ids: [],
        buy_qty: 1,
        get_same_as_buy: true,
        get_product_ids: [],
        get_category_ids: [],
        get_qty: 1,
        get_percent_off: 100,
        bundle_price_omr: '2.500',
        bundle_groups: [{ label: '', label_ar: '', product_ids: [], qty: 1 }],
        mb_product_ids: [],
        mb_category_ids: [],
        mb_qty: 3,
        mb_price_omr: '1.000',
        cf_product_ids: [],
        cf_category_ids: [],
        cf_qty: 3,
        cf_free_count: 1,
        sg_min_subtotal_omr: '5.000',
        sg_reward_type: 'percent_off',
        sg_percent: 10,
        sg_fixed_omr: '0.500',
        sg_reward_product_id: null,
        auto_apply: true,
        validity_start: '',
        validity_end: '',
        dayofweek_mask: 127,
        time_start: '',
        time_end: '',
        all_branches: true,
        branch_ids: [],
        max_per_order: '',
    };
}

// Day-of-week chip toggles. Sun=1..Sat=64 (the discounts convention).
const DOW_BITS: { key: string; bit: number }[] = [
    { key: 'sun', bit: 1 },
    { key: 'mon', bit: 2 },
    { key: 'tue', bit: 4 },
    { key: 'wed', bit: 8 },
    { key: 'thu', bit: 16 },
    { key: 'fri', bit: 32 },
    { key: 'sat', bit: 64 },
];

function isDowOn(bit: number): boolean {
    return (form.dayofweek_mask & bit) !== 0;
}

function toggleDow(bit: number): void {
    if (isDowOn(bit)) {
        form.dayofweek_mask &= ~bit;
    } else {
        form.dayofweek_mask |= bit;
    }
}

const isBundle = computed(() => form.type === 'bundle');

function addBundleGroup(): void {
    form.bundle_groups.push({ label: '', label_ar: '', product_ids: [], qty: 1 });
}

function removeBundleGroup(index: number): void {
    if (form.bundle_groups.length > 1) {
        form.bundle_groups.splice(index, 1);
    }
}

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    Object.assign(form, defaultForm());
    modalError.value = null;
    modalOpen.value = true;
}

function openEdit(o: Offer): void {
    modalMode.value = 'edit';
    modalTarget.value = o;
    Object.assign(form, defaultForm());
    form.name = o.name;
    form.name_ar = o.name_ar ?? '';
    form.type = o.type;
    form.auto_apply = o.auto_apply;
    form.validity_start = o.validity_start?.slice(0, 16) ?? '';
    form.validity_end = o.validity_end?.slice(0, 16) ?? '';
    form.dayofweek_mask = o.dayofweek_mask ?? 127;
    form.time_start = o.time_start?.slice(0, 5) ?? '';
    form.time_end = o.time_end?.slice(0, 5) ?? '';
    form.all_branches = o.branch_scope_json === null || o.branch_scope_json.length === 0;
    form.branch_ids = o.branch_scope_json ?? [];
    form.max_per_order = o.max_per_order !== null ? String(o.max_per_order) : '';

    if (o.type === 'bogo') {
        const c = o.config as BogoConfig;
        form.buy_product_ids = [...c.buy.product_ids];
        form.buy_category_ids = [...c.buy.category_ids];
        form.buy_qty = c.buy.qty;
        form.get_same_as_buy = c.get.same_as_buy;
        form.get_product_ids = [...c.get.product_ids];
        form.get_category_ids = [...c.get.category_ids];
        form.get_qty = c.get.qty;
        form.get_percent_off = c.get.percent_off;
    } else if (o.type === 'bundle') {
        const c = o.config as BundleConfig;
        form.bundle_price_omr = toOmr(c.price_baisas);
        form.bundle_groups = c.groups.map((g) => ({
            label: g.label,
            label_ar: g.label_ar ?? '',
            product_ids: [...g.product_ids],
            qty: g.qty,
        }));
    } else if (o.type === 'multi_buy') {
        const c = o.config as MultiBuyConfig;
        form.mb_product_ids = [...c.product_ids];
        form.mb_category_ids = [...c.category_ids];
        form.mb_qty = c.qty;
        form.mb_price_omr = toOmr(c.price_baisas);
    } else if (o.type === 'cheapest_free') {
        const c = o.config as CheapestFreeConfig;
        form.cf_product_ids = [...c.product_ids];
        form.cf_category_ids = [...c.category_ids];
        form.cf_qty = c.qty;
        form.cf_free_count = c.free_count;
    } else {
        const c = o.config as SpendGetConfig;
        form.sg_min_subtotal_omr = toOmr(c.min_subtotal_baisas);
        form.sg_reward_type = c.reward_type;
        if (c.reward_type === 'percent_off') {
            form.sg_percent = Number(c.reward_value ?? 10);
        } else if (c.reward_type === 'fixed_off') {
            form.sg_fixed_omr = toOmr(Number(c.reward_value ?? 0));
        }
        form.sg_reward_product_id = c.reward_product_id;
    }

    modalError.value = null;
    modalOpen.value = true;
}

function buildConfig(): OfferConfig {
    switch (form.type) {
        case 'bogo':
            return {
                buy: {
                    product_ids: form.buy_product_ids,
                    category_ids: form.buy_category_ids,
                    qty: form.buy_qty,
                },
                get: {
                    same_as_buy: form.get_same_as_buy,
                    product_ids: form.get_same_as_buy ? [] : form.get_product_ids,
                    category_ids: form.get_same_as_buy ? [] : form.get_category_ids,
                    qty: form.get_qty,
                    percent_off: form.get_percent_off,
                },
            } satisfies BogoConfig;
        case 'bundle':
            return {
                price_baisas: toBaisas(form.bundle_price_omr),
                groups: form.bundle_groups.map((g) => ({
                    label: g.label,
                    label_ar: g.label_ar.trim() !== '' ? g.label_ar : null,
                    product_ids: g.product_ids,
                    qty: g.qty,
                })),
            } satisfies BundleConfig;
        case 'multi_buy':
            return {
                product_ids: form.mb_product_ids,
                category_ids: form.mb_category_ids,
                qty: form.mb_qty,
                price_baisas: toBaisas(form.mb_price_omr),
            } satisfies MultiBuyConfig;
        case 'cheapest_free':
            return {
                product_ids: form.cf_product_ids,
                category_ids: form.cf_category_ids,
                qty: form.cf_qty,
                free_count: form.cf_free_count,
            } satisfies CheapestFreeConfig;
        case 'spend_get':
            return {
                min_subtotal_baisas: toBaisas(form.sg_min_subtotal_omr),
                reward_type: form.sg_reward_type,
                reward_value: form.sg_reward_type === 'percent_off'
                    ? form.sg_percent
                    : form.sg_reward_type === 'fixed_off'
                        ? toBaisas(form.sg_fixed_omr)
                        : null,
                reward_product_id: form.sg_reward_type === 'free_product' ? form.sg_reward_product_id : null,
            } satisfies SpendGetConfig;
    }
}

async function submitModal(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    try {
        const payload: CreateOfferPayload = {
            name: form.name,
            name_ar: form.name_ar.trim() !== '' ? form.name_ar : null,
            type: form.type,
            config: buildConfig(),
            // Bundle is always cashier-picked; the server forces false anyway.
            auto_apply: isBundle.value ? false : form.auto_apply,
            validity_start: form.validity_start || null,
            validity_end: form.validity_end || null,
            dayofweek_mask: form.dayofweek_mask === 127 ? null : form.dayofweek_mask,
            time_start: form.time_start ? `${form.time_start}:00` : null,
            time_end: form.time_end ? `${form.time_end}:00` : null,
            branch_scope_json: form.all_branches ? null : form.branch_ids,
            max_per_order: form.max_per_order !== '' ? Number.parseInt(form.max_per_order, 10) : null,
        };

        let offer: Offer;
        if (modalMode.value === 'create') {
            const r = await createOffer(payload);
            offer = r.data;
        } else if (modalTarget.value) {
            const r = await updateOffer(modalTarget.value.uuid, payload);
            offer = r.data;
        } else {
            return;
        }

        if (modalMode.value === 'create') {
            offers.value = [offer, ...offers.value];
        } else {
            const idx = offers.value.findIndex((o) => o.uuid === offer.uuid);
            if (idx >= 0) offers.value[idx] = offer;
        }
        modalOpen.value = false;
    } catch (err) {
        if (err instanceof ApiError) {
            const p = err.payload as { message?: string; errors?: Record<string, string[]> } | null;
            modalError.value = p?.errors ? Object.values(p.errors).flat().join(' ') : (p?.message ?? t('offers.errors.save_failed'));
        } else {
            modalError.value = t('offers.errors.save_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

// ---- Pause / resume / delete -----------------------------------
async function togglePause(o: Offer): Promise<void> {
    try {
        const r = o.status === 'active' ? await pauseOffer(o.uuid) : await resumeOffer(o.uuid);
        const idx = offers.value.findIndex((x) => x.uuid === r.data.uuid);
        if (idx >= 0) offers.value[idx] = r.data;
    } catch {
        // surface in a toast in a future iteration; for now silent
    }
}

const toDelete = ref<Offer | null>(null);
const deleteBusy = ref(false);

async function performDelete(): Promise<void> {
    if (!toDelete.value) return;
    deleteBusy.value = true;
    try {
        await deleteOffer(toDelete.value.uuid);
        offers.value = offers.value.filter((o) => o.uuid !== toDelete.value!.uuid);
        toDelete.value = null;
    } finally {
        deleteBusy.value = false;
    }
}

// ---- Display helpers -------------------------------------------
function statusBadgeClass(o: Offer): string {
    if (o.status === 'paused') return 'bg-amber-100 text-amber-700';
    return o.currently_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
}

function statusLabel(o: Offer): string {
    if (o.status === 'paused') return t('offers.statuses.paused');
    return o.currently_active
        ? t('offers.statuses.active_now')
        : t('offers.statuses.scheduled');
}

const OFFER_TYPES: OfferType[] = ['bogo', 'bundle', 'multi_buy', 'cheapest_free', 'spend_get'];
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('offers.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('offers.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('offers.subtitle') }}
                    </p>
                </div>
                <div v-if="canManage">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-700"
                        @click="openCreate"
                    >
                        <Plus class="size-4" />
                        {{ t('offers.actions.add') }}
                    </button>
                </div>
            </div>

            <!-- Search -->
            <input
                v-model="search"
                type="search"
                :placeholder="t('offers.search_placeholder')"
                class="w-full max-w-md rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
            >

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="filteredOffers.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <BadgePercent class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('offers.empty_state') }}</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.table.type') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.table.max_per_order') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="o in filteredOffers" :key="o.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">
                                        {{ o.name }}
                                        <span v-if="o.auto_apply" class="ms-1 inline-flex items-center rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-teal-700">{{ t('offers.flags.auto') }}</span>
                                        <span v-else-if="o.type === 'bundle'" class="ms-1 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">{{ t('offers.flags.cashier') }}</span>
                                    </span>
                                    <span v-if="o.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ o.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                        {{ t(`offers.types.${o.type}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <span v-if="o.max_per_order === null" class="text-slate-400">—</span>
                                    <span v-else>{{ o.max_per_order }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(o)">
                                        {{ statusLabel(o) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button v-if="canManage && o.status === 'active'" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50" @click="togglePause(o)">
                                            <Pause class="size-3.5" />
                                            {{ t('offers.actions.pause') }}
                                        </button>
                                        <button v-else-if="canManage && o.status === 'paused'" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50" @click="togglePause(o)">
                                            <PlayCircle class="size-3.5" />
                                            {{ t('offers.actions.resume') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="openEdit(o)">
                                            <Pencil class="size-3.5" />
                                            {{ t('offers.actions.edit') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50" @click="toDelete = o">
                                            <Trash2 class="size-3.5" />
                                            {{ t('offers.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ============== CREATE / EDIT MODAL =============== -->
        <BaseModal
            v-if="modalOpen"
            :title="modalMode === 'create' ? t('offers.modal.create_title') : t('offers.modal.edit_title')"
            size="3xl"
            :loading="modalBusy"
            @close="modalOpen = false"
        >
                <form id="offer-form" class="space-y-5" @submit.prevent="submitModal">
                    <div v-if="modalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ modalError }}
                    </div>

                    <!-- Name EN/AR -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ t('offers.fields.name') }}</label>
                            <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ t('offers.fields.name_ar') }}</label>
                            <input v-model="form.name_ar" type="text" dir="rtl" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                    </div>

                    <!-- TYPE PICKER -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('offers.fields.type') }}</label>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="ty in OFFER_TYPES"
                                :key="ty"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                :class="form.type === ty ? 'border-teal-300 bg-teal-100 text-teal-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                @click="form.type = ty"
                            >
                                {{ t(`offers.types.${ty}`) }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t(`offers.type_hints.${form.type}`) }}</p>
                    </div>

                    <!-- ============ BOGO ============ -->
                    <template v-if="form.type === 'bogo'">
                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.bogo.buy_section') }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.products') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.buy_product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ p.name }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.categories') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.buy_category_ids" type="checkbox" :value="c.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ c.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="w-32">
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.bogo.buy_qty') }}</label>
                                <input v-model.number="form.buy_qty" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </fieldset>

                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.bogo.get_section') }}</legend>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="form.get_same_as_buy" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="text-sm font-medium text-slate-700">{{ t('offers.bogo.same_as_buy') }}</span>
                            </label>
                            <div v-if="!form.get_same_as_buy" class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.products') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.get_product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ p.name }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.categories') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.get_category_ids" type="checkbox" :value="c.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ c.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.bogo.get_qty') }}</label>
                                    <input v-model.number="form.get_qty" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.bogo.percent_off') }}</label>
                                    <input v-model.number="form.get_percent_off" type="number" min="1" max="100" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-500">{{ t('offers.bogo.percent_hint') }}</p>
                        </fieldset>
                    </template>

                    <!-- ============ BUNDLE ============ -->
                    <template v-else-if="form.type === 'bundle'">
                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.bundle.section') }}</legend>
                            <div class="w-44">
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.bundle.price') }}</label>
                                <div class="mt-1 flex items-center gap-2">
                                    <input v-model="form.bundle_price_omr" type="text" inputmode="decimal" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <span class="text-sm text-slate-600">OMR</span>
                                </div>
                            </div>

                            <div v-for="(g, i) in form.bundle_groups" :key="i" class="rounded-lg border border-slate-200 bg-white p-3 space-y-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('offers.bundle.group_n', { n: i + 1 }) }}</p>
                                    <button v-if="form.bundle_groups.length > 1" type="button" class="text-xs font-semibold text-rose-600 hover:underline" @click="removeBundleGroup(i)">
                                        {{ t('offers.bundle.remove_group') }}
                                    </button>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600">{{ t('offers.bundle.group_label') }}</label>
                                        <input v-model="g.label" type="text" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600">{{ t('offers.bundle.group_label_ar') }}</label>
                                        <input v-model="g.label_ar" type="text" dir="rtl" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                    <div class="w-28">
                                        <label class="block text-xs font-medium text-slate-600">{{ t('offers.bundle.group_qty') }}</label>
                                        <input v-model.number="g.qty" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.products') }}</p>
                                    <div class="max-h-32 overflow-y-auto rounded border border-slate-200 p-1 grid grid-cols-2 gap-1">
                                        <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="g.product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ p.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 px-3 py-1.5 text-xs font-semibold text-teal-700 hover:bg-teal-50" @click="addBundleGroup">
                                <Plus class="size-3.5" />
                                {{ t('offers.bundle.add_group') }}
                            </button>
                        </fieldset>
                    </template>

                    <!-- ============ MULTI BUY ============ -->
                    <template v-else-if="form.type === 'multi_buy'">
                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.multi_buy.section') }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.products') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.mb_product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ p.name }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.categories') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.mb_category_ids" type="checkbox" :value="c.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ c.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.multi_buy.qty') }}</label>
                                    <input v-model.number="form.mb_qty" type="number" min="2" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                                <div class="w-44">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.multi_buy.price') }}</label>
                                    <div class="mt-1 flex items-center gap-2">
                                        <input v-model="form.mb_price_omr" type="text" inputmode="decimal" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <span class="text-sm text-slate-600">OMR</span>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </template>

                    <!-- ============ CHEAPEST FREE ============ -->
                    <template v-else-if="form.type === 'cheapest_free'">
                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.cheapest_free.section') }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.products') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.cf_product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ p.name }}</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.categories') }}</p>
                                    <div class="max-h-36 overflow-y-auto rounded border border-slate-200 bg-white p-1">
                                        <label v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                            <input v-model="form.cf_category_ids" type="checkbox" :value="c.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                            <span class="text-xs text-slate-700">{{ c.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.cheapest_free.qty') }}</label>
                                    <input v-model.number="form.cf_qty" type="number" min="2" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-slate-600">{{ t('offers.cheapest_free.free_count') }}</label>
                                    <input v-model.number="form.cf_free_count" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-500">{{ t('offers.cheapest_free.hint') }}</p>
                        </fieldset>
                    </template>

                    <!-- ============ SPEND GET ============ -->
                    <template v-else>
                        <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('offers.spend_get.section') }}</legend>
                            <div class="w-44">
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.spend_get.min_subtotal') }}</label>
                                <div class="mt-1 flex items-center gap-2">
                                    <input v-model="form.sg_min_subtotal_omr" type="text" inputmode="decimal" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <span class="text-sm text-slate-600">OMR</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.spend_get.reward_type') }}</label>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <button
                                        v-for="rt in (['percent_off', 'fixed_off', 'free_product'] as SpendGetRewardType[])"
                                        :key="rt"
                                        type="button"
                                        class="rounded-md border px-3 py-1.5 text-xs font-semibold transition"
                                        :class="form.sg_reward_type === rt ? 'border-teal-300 bg-teal-100 text-teal-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                                        @click="form.sg_reward_type = rt"
                                    >
                                        {{ t(`offers.spend_get.reward_types.${rt}`) }}
                                    </button>
                                </div>
                            </div>
                            <div v-if="form.sg_reward_type === 'percent_off'" class="w-32">
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.spend_get.percent') }}</label>
                                <input v-model.number="form.sg_percent" type="number" min="1" max="100" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div v-else-if="form.sg_reward_type === 'fixed_off'" class="w-44">
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.spend_get.fixed') }}</label>
                                <div class="mt-1 flex items-center gap-2">
                                    <input v-model="form.sg_fixed_omr" type="text" inputmode="decimal" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <span class="text-sm text-slate-600">OMR</span>
                                </div>
                            </div>
                            <div v-else>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.spend_get.free_product') }}</label>
                                <select v-model="form.sg_reward_product_id" class="mt-1 block w-full max-w-xs rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <option :value="null" disabled>{{ t('offers.spend_get.pick_product') }}</option>
                                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </div>
                        </fieldset>
                    </template>

                    <!-- ============ SHARED SECTION ============ -->
                    <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700 inline-flex items-center gap-2">
                            <Calendar class="size-4 text-teal-700" />
                            {{ t('offers.fields.validity') }}
                        </legend>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.fields.validity_start') }}</label>
                                <input v-model="form.validity_start" type="datetime-local" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.fields.validity_end') }}</label>
                                <input v-model="form.validity_end" type="datetime-local" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </div>

                        <!-- Day-of-week chips -->
                        <div>
                            <p class="block text-xs font-medium text-slate-600 mb-1">{{ t('offers.fields.dayofweek') }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="d in DOW_BITS" :key="d.key" type="button" class="rounded-md border px-3 py-1 text-xs font-semibold transition" :class="isDowOn(d.bit) ? 'border-teal-300 bg-teal-100 text-teal-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'" @click="toggleDow(d.bit)">
                                    {{ t(`offers.dow.${d.key}`) }}
                                </button>
                            </div>
                        </div>

                        <!-- Time-of-day -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.fields.time_start') }}</label>
                                <input v-model="form.time_start" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('offers.fields.time_end') }}</label>
                                <input v-model="form.time_end" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-500">{{ t('offers.fields.time_hint') }}</p>

                        <!-- Branch scope -->
                        <div>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="form.all_branches" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="text-sm font-medium text-slate-700">{{ t('offers.fields.all_branches') }}</span>
                            </label>
                            <div v-if="!form.all_branches" class="mt-2 max-h-32 overflow-y-auto rounded border border-slate-200 bg-white p-1 grid grid-cols-2 gap-1">
                                <label v-for="b in branches" :key="b.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                                    <input v-model="form.branch_ids" type="checkbox" :value="b.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                    <span class="text-xs text-slate-700">{{ b.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Max per order -->
                        <div class="w-44">
                            <label class="block text-xs font-medium text-slate-600">{{ t('offers.fields.max_per_order') }}</label>
                            <input v-model="form.max_per_order" type="number" min="1" :placeholder="t('offers.fields.max_per_order_placeholder')" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                    </fieldset>

                    <!-- Auto-apply: hidden + forced off for bundle -->
                    <label v-if="!isBundle" class="inline-flex items-center gap-2">
                        <input v-model="form.auto_apply" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        <span class="text-sm font-medium text-slate-700">{{ t('offers.fields.auto_apply') }}</span>
                    </label>
                    <p v-if="!isBundle" class="text-xs text-slate-500">{{ t('offers.fields.auto_apply_hint') }}</p>
                    <p v-else class="text-xs text-slate-500">{{ t('offers.fields.bundle_cashier_hint') }}</p>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="modalOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="offer-form" :disabled="modalBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-50">
                        {{ modalBusy ? t('common.saving') : t('common.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============== DELETE CONFIRM =============== -->
        <BaseModal
            v-if="toDelete"
            :title="t('offers.delete.title')"
            size="md"
            :loading="deleteBusy"
            @close="toDelete = null"
        >
                <div class="space-y-4">
                    <p class="text-sm text-slate-600">{{ t('offers.delete.confirm', { name: toDelete.name }) }}</p>
                </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="toDelete = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 disabled:opacity-50" @click="performDelete">
                        {{ deleteBusy ? t('common.deleting') : t('offers.actions.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

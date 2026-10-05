<script setup lang="ts">
/**
 * LAUNCH-P4 B2 — the combos editor (owner decision 7).
 *
 * A combo is a set price plus choice slots ("Main", "Side", "Drink"). Each
 * slot lets the customer pick between a least and a most number of items;
 * each item may cost extra (the same on every channel) and may be
 * pre-selected. Items keep their own add-ons at their own prices, use their
 * own stock and go to the kitchen. The combo's own price follows the channel
 * rules like any product (in store / QR / delivery / per provider), and it
 * is sold only where its channels say.
 *
 * Create: POST /api/combos. Edit: PUT /api/combos/{uuid} (slots keep their
 * ids). The preview shows the price range before the items' add-ons.
 */
import { ArrowLeft, CalendarRange, Layers, Plus, Star, Timer, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ChannelsEditor from '@/Pages/Merchant/Catalogue/ChannelsEditor.vue';
import ImageUploadField from '@/Pages/Merchant/Catalogue/ImageUploadField.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createCombo,
    getCombo,
    listAddonLinkOptions,
    listCategories,
    updateCombo,
    type AddonLinkOption,
    type Category,
    type Combo,
    type ProductStatus,
    type SaveComboPayload,
} from '@/lib/api/catalogue';
import { listDeliveryProviders, type DeliveryProvider } from '@/lib/api/deliveryProviders';
import { listBranches, type Branch as BranchLite } from '@/lib/api/branches';
import { branchScopePayload, providerPayload, providerRowsFrom, selectedBranchIds, type ProviderChannelRow } from '@/lib/channels';
import { comboPriceRange, slotIssues } from '@/lib/combo';
// LAUNCH review add-on — main slot, dates, cooking time.
import {
    canBeMain,
    comboCookingFigure,
    cookingPayload,
    cookingProblem,
    datesProblem,
    limitedSlotIndexes,
    mainIssues,
    saleDay,
} from '@/lib/menuExtras';
import { MerchantPermission } from '@/lib/permissions';
import { authState } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { can } = usePermissions();

const canManage = computed(() => can(MerchantPermission.CatalogueManage));
const editUuid = route.name === 'merchant.catalogue.combo-edit' ? String(route.params.uuid) : null;
const isEdit = editUuid !== null;
const isUnrestricted = computed(() => (authState.user?.branch_scope ?? null) === null);

// ---- Reference data ----------------------------------------------
const categories = ref<Category[]>([]);
const items = ref<AddonLinkOption[]>([]);
const providers = ref<DeliveryProvider[]>([]);
const branches = ref<BranchLite[]>([]);
const activeProviders = computed(() => providers.value.filter((p) => p.is_active));

// ---- Form ----------------------------------------------------------
interface SlotOptionForm { key: string; product_uuid: string; extra_price: string; is_default: boolean; label: string | null }
interface SlotForm { key: string; id: number | null; name: string; name_ar: string; min_choices: number; max_choices: number; is_main: boolean; options: SlotOptionForm[] }

let seq = 0;
const nextKey = (p: string): string => `${p}-${++seq}`;

const form = reactive<{
    name: string;
    name_ar: string;
    description: string;
    description_ar: string;
    image_url: string;
    category_id: number | null;
    sku: string;
    barcode: string;
    base_price: string;
    delivery_price: string;
    sold_in_store: boolean;
    show_on_customer_tablet: boolean;
    sold_on_delivery: boolean;
    status: ProductStatus;
    branch_scope: 'all' | 'selected';
    branch_ids: number[];
    slots: SlotForm[];
    /** LAUNCH review add-on — daily hours ('HH:MM'), dates, cooking time. */
    available_from: string;
    available_until: string;
    on_sale_from: string;
    on_sale_until: string;
    cooking_minutes: string;
}>({
    name: '',
    name_ar: '',
    description: '',
    description_ar: '',
    image_url: '',
    category_id: null,
    sku: '',
    barcode: '',
    base_price: '',
    delivery_price: '',
    sold_in_store: true,
    show_on_customer_tablet: true,
    sold_on_delivery: true,
    status: 'active',
    branch_scope: 'all',
    branch_ids: [],
    slots: [],
    available_from: '',
    available_until: '',
    on_sale_from: '',
    on_sale_until: '',
    cooking_minutes: '',
});
const providerRows = ref<Record<string, ProviderChannelRow>>({});

function blankOption(): SlotOptionForm {
    return { key: nextKey('opt'), product_uuid: '', extra_price: '0', is_default: false, label: null };
}

function addSlot(): void {
    form.slots.push({ key: nextKey('slot'), id: null, name: '', name_ar: '', min_choices: 1, max_choices: 1, is_main: false, options: [blankOption()] });
}

function removeSlot(index: number): void {
    form.slots.splice(index, 1);
}

function addOption(slot: SlotForm): void {
    slot.options.push(blankOption());
}

function removeOption(slot: SlotForm, index: number): void {
    slot.options.splice(index, 1);
}

function itemName(option: SlotOptionForm): string {
    const item = items.value.find((i) => i.uuid === option.product_uuid);
    if (!item) return option.label ?? '—';
    return locale.value === 'ar' && item.name_ar ? item.name_ar : item.name;
}

// ---- Preview ---------------------------------------------------------
const rangeInStore = computed(() => comboPriceRange(form.base_price || '0', form.slots));
const rangeDelivery = computed(() => comboPriceRange(form.delivery_price || form.base_price || '0', form.slots));

function issueText(slot: SlotForm): string[] {
    return slotIssues(slot).map((issue) => t(`combos.issues.${issue}`));
}

// ---- LAUNCH review add-on: main slot, dates, cooking time ------------
/** "Make it a meal?" — at most one main, on a slot with least = most = 1. */
function setMain(index: number | null): void {
    form.slots.forEach((slot, i) => { slot.is_main = i === index; });
}
const mainProblems = computed(() => mainIssues(form.slots));
function mainProblem(index: number): string | null {
    const found = mainProblems.value.find((p) => p.index === index);
    return found ? t(`meals.issues.${found.issue}`, { n: index + 1 }) : null;
}
/** Required slots whose every item has sale dates (a warning, never a block). */
const limitedSlots = computed(() => limitedSlotIndexes(form.slots, (uuid) => items.value.find((i) => i.uuid === uuid)));
const datesError = computed(() => (datesProblem(form.on_sale_from, form.on_sale_until) ? t('menu_extras.until_before_from') : null));
const cookingError = computed(() => (cookingProblem(form.cooking_minutes) ? t('menu_extras.cooking_range') : null));
/** What customers see when the combo has no time of its own: its longest item. */
const itemsCookingFigure = computed(() => comboCookingFigure('', form.slots.flatMap((slot) => slot.options
    .map((o) => items.value.find((i) => i.uuid === o.product_uuid)?.cooking_minutes ?? null))));

// ---- Load ----------------------------------------------------------
const loading = ref(true);
const loadError = ref<string | null>(null);
const saving = ref(false);
const saveError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

function fieldError(key: string): string | null {
    const exact = fieldErrors.value[key];
    if (exact && exact.length > 0) return exact[0]!;
    for (const [k, messages] of Object.entries(fieldErrors.value)) {
        if (k.startsWith(`${key}.`) && messages.length > 0) return messages[0]!;
    }
    return null;
}

function prefill(combo: Combo): void {
    form.name = combo.name;
    form.name_ar = combo.name_ar ?? '';
    form.description = combo.description ?? '';
    form.description_ar = combo.description_ar ?? '';
    form.image_url = combo.image_url ?? '';
    form.category_id = combo.category_id;
    form.sku = combo.sku ?? '';
    form.barcode = combo.barcode ?? '';
    form.base_price = combo.base_price;
    form.delivery_price = combo.delivery_price ?? '';
    form.sold_in_store = combo.sold_in_store ?? true;
    form.show_on_customer_tablet = combo.show_on_customer_tablet ?? true;
    form.sold_on_delivery = combo.sold_on_delivery ?? true;
    form.status = (combo.status ?? 'active') as ProductStatus;
    form.branch_scope = combo.branch_scope ?? 'all';
    form.branch_ids = selectedBranchIds(combo.branches);
    // LAUNCH review add-on — daily hours are kept (no longer wiped on save),
    // plus the dates and the combo's own cooking time.
    form.available_from = combo.available_from?.slice(0, 5) ?? '';
    form.available_until = combo.available_until?.slice(0, 5) ?? '';
    form.on_sale_from = combo.on_sale_from ?? '';
    form.on_sale_until = combo.on_sale_until ?? '';
    form.cooking_minutes = combo.cooking_minutes != null ? String(combo.cooking_minutes) : '';
    form.slots = (combo.combo?.slots ?? []).map((slot) => ({
        key: nextKey('slot'),
        id: slot.id,
        name: slot.name,
        name_ar: slot.name_ar ?? '',
        min_choices: slot.min_choices,
        max_choices: slot.max_choices,
        is_main: slot.is_main ?? false,
        options: slot.options.map((o) => ({
            key: nextKey('opt'),
            product_uuid: o.product_uuid,
            extra_price: o.extra_price,
            is_default: o.is_default,
            label: o.product_available ? o.product_name : `${o.product_name ?? '—'} (${t('combos.unavailable_item')})`,
        })),
    }));
    providerRows.value = providerRowsFrom(activeProviders.value, combo.delivery_provider_prices ?? []);
}

onMounted(async () => {
    loading.value = true;
    try {
        await Promise.all([
            listCategories().then((r) => { categories.value = r.data; }).catch(() => { categories.value = []; }),
            listAddonLinkOptions().then((r) => { items.value = r.data; }).catch(() => { items.value = []; }),
            listDeliveryProviders().then((r) => { providers.value = r.data; }).catch(() => { providers.value = []; }),
            listBranches().then((r) => { branches.value = r.data; }).catch(() => { branches.value = []; }),
        ]);
        if (isEdit) {
            prefill((await getCombo(editUuid!)).data);
        } else {
            addSlot();
            providerRows.value = providerRowsFrom(activeProviders.value, []);
        }
    } catch (err) {
        loadError.value = err instanceof ApiError && err.status === 404 ? t('combos.not_found') : t('combos.load_failed');
    } finally {
        loading.value = false;
    }
});

// ---- Save ----------------------------------------------------------
function payload(): SaveComboPayload {
    return {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        description: form.description.trim() || null,
        description_ar: form.description_ar.trim() || null,
        image_url: form.image_url.trim() || null,
        category_id: form.category_id,
        sku: form.sku.trim() || null,
        barcode: form.barcode.trim() || null,
        base_price: String(form.base_price).trim(),
        delivery_price: String(form.delivery_price ?? '').trim() === '' ? null : String(form.delivery_price).trim(),
        sold_in_store: form.sold_in_store,
        show_on_customer_tablet: form.show_on_customer_tablet,
        sold_on_delivery: form.sold_on_delivery,
        // LAUNCH review add-on — the daily hours as set (they used to be sent
        // as null, wiping them), the dates and the cooking time.
        available_from: form.available_from ? `${form.available_from.slice(0, 5)}:00` : null,
        available_until: form.available_until ? `${form.available_until.slice(0, 5)}:00` : null,
        on_sale_from: saleDay(form.on_sale_from),
        on_sale_until: saleDay(form.on_sale_until),
        cooking_minutes: cookingPayload(form.cooking_minutes),
        ...(isEdit ? { status: form.status } : {}),
        slots: form.slots.map((slot) => ({
            id: slot.id,
            name: slot.name.trim(),
            name_ar: slot.name_ar.trim() || null,
            min_choices: Number(slot.min_choices),
            max_choices: Number(slot.max_choices),
            is_main: slot.is_main,
            options: slot.options.map((o) => ({
                product_uuid: o.product_uuid,
                extra_price: String(o.extra_price ?? '').trim() === '' ? '0' : String(o.extra_price).trim(),
                is_default: o.is_default,
            })),
        })),
        // Edit: every active provider is sent (listed at the default price
        // removes its row); create: only the rows that differ.
        delivery_prices: isEdit
            ? activeProviders.value.map((p) => {
                const row = providerRows.value[p.uuid] ?? { listed: true, price: '' };
                const price = String(row.price ?? '').trim();
                return { provider_uuid: p.uuid, listed: row.listed, price: price === '' ? null : price };
            })
            : providerPayload(activeProviders.value, providerRows.value),
        branches: isUnrestricted.value ? branchScopePayload(form.branch_scope, form.branch_ids) : null,
    };
}

const blockingProblems = computed<string[]>(() => {
    const problems: string[] = [];
    if (form.name.trim() === '') problems.push(t('combos.name_required'));
    if (String(form.base_price).trim() === '') problems.push(t('combos.price_required'));
    if (form.slots.length === 0) problems.push(t('combos.issues.no_slots'));
    form.slots.forEach((slot, i) => {
        if (slot.name.trim() === '') problems.push(t('combos.issues.slot_name', { n: i + 1 }));
        if (slotIssues(slot).length > 0) problems.push(t('combos.issues.slot', { n: i + 1 }));
    });
    if (isUnrestricted.value && form.branch_scope === 'selected' && form.branch_ids.length === 0) problems.push(t('channels.pick_a_branch'));
    mainProblems.value.forEach((p) => problems.push(t(`meals.issues.${p.issue}`, { n: p.index + 1 })));
    if (datesError.value) problems.push(datesError.value);
    if (cookingError.value) problems.push(cookingError.value);
    return problems;
});

async function save(): Promise<void> {
    if (!canManage.value || blockingProblems.value.length > 0) return;
    saving.value = true;
    saveError.value = null;
    fieldErrors.value = {};
    try {
        if (isEdit) {
            await updateCombo(editUuid!, payload());
        } else {
            await createCombo(payload());
        }
        void router.push({ path: '/catalogue', query: { tab: 'products' } });
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            saveError.value = t('catalogue.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            saveError.value = String((err.payload as { message?: unknown }).message ?? t('combos.save_failed'));
        } else {
            saveError.value = t('combos.save_failed');
        }
        window.scrollTo({ top: 0 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl">
            <RouterLink :to="{ path: '/catalogue', query: { tab: 'products' } }" class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('catalogue.wizard.back') }}
            </RouterLink>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950">
                <Layers class="size-6 text-indigo-600" />
                {{ isEdit ? t('combos.edit_title') : t('combos.create_title') }}
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ t('combos.subtitle') }}</p>

            <div v-if="!canManage && !isEdit" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">{{ t('catalogue.wizard.forbidden') }}</div>
            <div v-else-if="loading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="loadError" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ loadError }}</div>

            <form v-else class="mt-5" data-test="combo-form" @submit.prevent="save">
              <!-- A catalogue viewer opens a combo read-only. -->
              <fieldset :disabled="!canManage" class="min-w-0 space-y-5">
                <div v-if="!canManage" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">{{ t('catalogue.wizard.readonly_hint') }}</div>
                <div v-if="saveError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ saveError }}</div>

                <!-- Basics -->
                <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.identity_title') }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                            <input v-model="form.name" type="text" maxlength="191" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="combo-name">
                            <span v-if="fieldError('name')" class="mt-1 block text-xs text-rose-600">{{ fieldError('name') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                            <input v-model="form.name_ar" type="text" dir="rtl" maxlength="191" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.category') }}</span>
                        <select v-model="form.category_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="null">{{ t('catalogue.uncategorized') }}</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.description') }}</span>
                            <textarea v-model="form.description" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('product_form.description_ar') }}</span>
                            <textarea v-model="form.description_ar" rows="2" dir="rtl" maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                    </div>
                    <div data-test="combo-photo">
                        <!-- LAUNCH-P4 B5 — upload (resized in the browser) or a link. -->
                        <ImageUploadField v-model="form.image_url" kind="product" :disabled="!canManage" :error="fieldError('image_url')" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.sku') }}</span>
                            <input v-model="form.sku" type="text" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <span v-if="fieldError('sku')" class="mt-1 block text-xs text-rose-600">{{ fieldError('sku') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.barcode') }}</span>
                            <input v-model="form.barcode" type="text" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <span v-if="fieldError('barcode')" class="mt-1 block text-xs text-rose-600">{{ fieldError('barcode') }}</span>
                        </label>
                        <label v-if="isEdit" class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="active">{{ t('catalogue.statuses.active') }}</option>
                                <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                            </select>
                        </label>
                    </div>
                </section>

                <!-- Price -->
                <section class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-slate-900">{{ t('combos.price_title') }}</h2>
                    <label class="block max-w-xs">
                        <span class="text-sm font-medium text-slate-700">{{ t('combos.price') }} (OMR) *</span>
                        <input v-model="form.base_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="combo-price">
                        <span v-if="fieldError('base_price')" class="mt-1 block text-xs text-rose-600">{{ fieldError('base_price') }}</span>
                    </label>
                    <p class="text-xs text-slate-500">{{ t('combos.price_hint') }}</p>
                </section>

                <!-- LAUNCH review add-on — when it is sold (daily hours + limited-time dates) and the cooking time. -->
                <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="combo-when">
                    <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <CalendarRange class="size-4 text-indigo-600" />
                        {{ t('menu_extras.when_title') }}
                    </h2>
                    <div>
                        <p class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.available_hours') }}</p>
                        <div class="mt-1 grid max-w-md grid-cols-2 gap-3">
                            <label class="block">
                                <span class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_from') }}</span>
                                <input v-model="form.available_from" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="combo-hours-from">
                            </label>
                            <label class="block">
                                <span class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_until') }}</span>
                                <input v-model="form.available_until" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="combo-hours-until">
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.available_hours_hint') }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-slate-700">{{ t('menu_extras.dates_title') }}</p>
                        <div class="mt-1 grid max-w-md grid-cols-2 gap-3">
                            <label class="block">
                                <span class="block text-xs font-medium text-slate-600">{{ t('menu_extras.on_sale_from') }}</span>
                                <input v-model="form.on_sale_from" type="date" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="combo-sale-from">
                            </label>
                            <label class="block">
                                <span class="block text-xs font-medium text-slate-600">{{ t('menu_extras.on_sale_until') }}</span>
                                <input v-model="form.on_sale_until" type="date" :min="form.on_sale_from || undefined" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="combo-sale-until">
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('menu_extras.dates_hint') }}</p>
                        <p v-if="datesError || fieldError('on_sale_until')" class="mt-1 text-xs font-semibold text-rose-600">{{ datesError ?? fieldError('on_sale_until') }}</p>
                    </div>
                    <label class="block max-w-xs">
                        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-700"><Timer class="size-3.5 text-slate-500" /> {{ t('menu_extras.cooking_minutes') }}</span>
                        <input v-model="form.cooking_minutes" type="number" min="0" max="240" step="1" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="combo-cooking">
                        <span class="mt-1 block text-xs text-slate-500">
                            {{ itemsCookingFigure !== null ? t('menu_extras.combo_cooking_hint', { minutes: itemsCookingFigure }) : t('menu_extras.combo_cooking_hint_none') }}
                        </span>
                        <span v-if="cookingError || fieldError('cooking_minutes')" class="mt-1 block text-xs font-semibold text-rose-600">{{ cookingError ?? fieldError('cooking_minutes') }}</span>
                    </label>
                </section>

                <!-- Channels (B3) -->
                <ChannelsEditor
                    v-model:sold-in-store="form.sold_in_store"
                    v-model:show-on-qr="form.show_on_customer_tablet"
                    v-model:sold-on-delivery="form.sold_on_delivery"
                    v-model:delivery-price="form.delivery_price"
                    v-model:provider-rows="providerRows"
                    v-model:branch-scope="form.branch_scope"
                    v-model:branch-ids="form.branch_ids"
                    :providers="activeProviders"
                    :branches="branches"
                    :base-price="form.base_price"
                    :can-edit-branches="isUnrestricted"
                    :delivery-price-error="fieldError('delivery_price')"
                    :providers-error="fieldError('delivery_prices')"
                    :branches-error="fieldError('branches')"
                />

                <!-- Slots -->
                <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="combo-slots">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ t('combos.slots_title') }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ t('combos.slots_hint') }}</p>
                    </div>
                    <!-- LAUNCH review add-on — "Make it a meal?": one slot may be the main item. -->
                    <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2" data-test="combo-main">
                        <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-900"><Star class="size-3.5" /> {{ t('meals.main_title') }}</p>
                        <p class="mt-0.5 text-xs text-amber-800">{{ t('meals.main_hint') }}</p>
                        <label class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-medium text-slate-700">
                            <input type="radio" name="combo-main" :checked="!form.slots.some((s) => s.is_main)" class="border-slate-300 text-amber-600 focus:ring-amber-200" data-test="combo-main-none" @change="setMain(null)">
                            {{ t('meals.no_main') }}
                        </label>
                    </div>

                    <article v-for="(slot, si) in form.slots" :key="slot.key" class="rounded-xl border border-slate-200 p-3" data-test="combo-slot">
                        <div class="grid gap-2 sm:grid-cols-[1fr_1fr_6rem_6rem_auto]">
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.slot_name') }} *</span>
                                <input v-model="slot.name" type="text" maxlength="64" :placeholder="t('combos.slot_name_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.fields.name_ar') }}</span>
                                <input v-model="slot.name_ar" type="text" dir="rtl" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.min') }}</span>
                                <input v-model.number="slot.min_choices" type="number" min="0" max="20" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="slot-min">
                            </label>
                            <label class="block">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('combos.max') }}</span>
                                <input v-model.number="slot.max_choices" type="number" min="1" max="20" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="slot-max">
                            </label>
                            <div class="flex items-end">
                                <button type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="removeSlot(si)">
                                    <Trash2 class="size-3" /> {{ t('combos.remove_slot') }}
                                </button>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('combos.choose_between', { min: slot.min_choices, max: slot.max_choices }) }}</p>
                        <label class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-medium" :class="canBeMain(slot) || slot.is_main ? 'text-slate-700' : 'text-slate-400'">
                            <input type="radio" name="combo-main" :checked="slot.is_main" :disabled="!canBeMain(slot) && !slot.is_main" class="border-slate-300 text-amber-600 focus:ring-amber-200" data-test="slot-main" @change="setMain(si)">
                            {{ t('meals.main_label') }}
                            <span v-if="!canBeMain(slot)" class="text-[11px] font-normal">— {{ t('meals.main_needs_single') }}</span>
                        </label>
                        <p v-if="mainProblem(si)" class="mt-1 text-xs font-semibold text-rose-700" data-test="slot-main-problem">{{ mainProblem(si) }}</p>
                        <p v-if="fieldError(`slots.${si}.is_main`)" class="mt-1 text-xs text-rose-600">{{ fieldError(`slots.${si}.is_main`) }}</p>

                        <table class="mt-2 w-full text-sm">
                            <thead class="text-[11px] uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="py-1 text-start font-semibold">{{ t('combos.item') }}</th>
                                    <th class="py-1 text-end font-semibold">{{ t('combos.extra_price') }}</th>
                                    <th class="py-1 text-center font-semibold">{{ t('combos.default') }}</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(option, oi) in slot.options" :key="option.key" data-test="combo-option">
                                    <td class="py-1.5 pe-2">
                                        <select v-model="option.product_uuid" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="option-item">
                                            <option value="">{{ t('combos.pick_item') }}</option>
                                            <option v-if="option.product_uuid !== '' && !items.some((i) => i.uuid === option.product_uuid)" :value="option.product_uuid">{{ itemName(option) }}</option>
                                            <option v-for="item in items" :key="item.uuid" :value="item.uuid">
                                                {{ locale === 'ar' && item.name_ar ? item.name_ar : item.name }}<template v-if="item.base_price"> — {{ item.base_price }}</template>
                                            </option>
                                        </select>
                                        <span v-if="fieldError(`slots.${si}.options.${oi}.product_uuid`)" class="mt-1 block text-xs text-rose-600">{{ fieldError(`slots.${si}.options.${oi}.product_uuid`) }}</span>
                                    </td>
                                    <td class="w-32 py-1.5 pe-2">
                                        <input v-model="option.extra_price" type="number" step="0.001" min="0" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-end text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="option-extra">
                                    </td>
                                    <td class="w-20 py-1.5 text-center">
                                        <input v-model="option.is_default" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="option-default">
                                    </td>
                                    <td class="w-10 py-1.5 text-end">
                                        <button type="button" class="rounded p-1 text-rose-500 hover:bg-rose-100" :title="t('combos.remove_item')" @click="removeOption(slot, oi)">
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="mt-2">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100" @click="addOption(slot)">
                                <Plus class="size-3" /> {{ t('combos.add_item') }}
                            </button>
                        </div>
                        <p v-if="limitedSlots.includes(si)" class="mt-2 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800" data-test="slot-limited-warning">{{ t('meals.limited_warning') }}</p>
                        <ul v-if="issueText(slot).length > 0" class="mt-2 space-y-0.5 text-xs font-semibold text-rose-700" data-test="slot-issues">
                            <li v-for="msg in issueText(slot)" :key="msg">{{ msg }}</li>
                        </ul>
                        <p v-if="fieldError(`slots.${si}`)" class="mt-1 text-xs text-rose-600">{{ fieldError(`slots.${si}`) }}</p>
                    </article>

                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100" data-test="add-slot" @click="addSlot">
                        <Plus class="size-3.5" /> {{ t('combos.add_slot') }}
                    </button>
                    <p v-if="fieldError('slots')" class="text-xs text-rose-600">{{ fieldError('slots') }}</p>
                </section>

                <!-- Price range preview -->
                <section class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-5 shadow-sm" data-test="combo-preview">
                    <h2 class="text-sm font-semibold text-indigo-900">{{ t('combos.preview_title') }}</h2>
                    <dl class="mt-2 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-indigo-700">{{ t('combos.preview_in_store') }}</dt>
                            <dd class="font-semibold tabular-nums text-indigo-950" dir="ltr">{{ rangeInStore.min }} – {{ rangeInStore.max }} OMR</dd>
                        </div>
                        <div v-if="form.sold_on_delivery">
                            <dt class="text-xs text-indigo-700">{{ t('combos.preview_delivery') }}</dt>
                            <dd class="font-semibold tabular-nums text-indigo-950" dir="ltr">{{ rangeDelivery.min }} – {{ rangeDelivery.max }} OMR</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-indigo-700">{{ t('combos.preview_hint') }}</p>
                </section>

                <ul v-if="canManage && blockingProblems.length > 0" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800" data-test="combo-problems">
                    <li v-for="msg in blockingProblems" :key="msg">{{ msg }}</li>
                </ul>

                <div v-if="canManage" class="flex justify-end pb-8">
                    <button type="submit" :disabled="saving || blockingProblems.length > 0" class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60" data-test="combo-save">
                        {{ saving ? t('catalogue.wizard.saving') : t('combos.save') }}
                    </button>
                </div>
              </fieldset>
            </form>
        </div>
    </MerchantLayout>
</template>

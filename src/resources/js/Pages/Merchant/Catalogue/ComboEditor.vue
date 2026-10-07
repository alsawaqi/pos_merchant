<script setup lang="ts">
/**
 * LAUNCH-P4 B2, rebuilt by the LAUNCH combo add-on — the combos editor.
 *
 * A combo is a fixed set under one name and one price: "Included items"
 * (a product × quantity, served every time, with optional upgrades at an
 * upgrade price) and "Choices" (a question, a category, pick N; the merchant
 * unticks items and may set an extra price on any). Items keep their own
 * add-ons at their own prices, use their own stock and go to the kitchen.
 * The combo's own price follows the channel rules like any product.
 *
 * Create: POST /api/combos. Edit: PUT /api/combos/{uuid} (lines keep their
 * ids). The preview shows the combo price and the range with extras.
 */
import { ArrowLeft, CalendarRange, Layers, Timer } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ChannelsEditor from '@/Pages/Merchant/Catalogue/ChannelsEditor.vue';
import ComboLinesEditor from '@/Pages/Merchant/Catalogue/ComboLinesEditor.vue';
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
import { comboPriceRange, draftsFrom, lineIssues, linesPayload, type LineDraft } from '@/lib/combo';
// LAUNCH review add-on — dates, cooking time.
import { comboCookingFigure, comboMenuFields, cookingProblem, datesProblem } from '@/lib/menuExtras';
import { MerchantPermission } from '@/lib/permissions';
// LAUNCH costs & allergens add-on — the combo's own target food cost %, its
// food cost (items at their cheapest) and its allergens (all items, every choice).
import AllergenChips from '@/Pages/Merchant/Catalogue/AllergenChips.vue';
import { baisasText, foodCostTone, hasFoodCostPct, pctText, targetPayload, targetProblem } from '@/lib/foodCost';
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
    /** LAUNCH combo add-on — included items and choices. */
    lines: LineDraft[];
    /** LAUNCH review add-on — daily hours ('HH:MM'), dates, cooking time. */
    available_from: string;
    available_until: string;
    on_sale_from: string;
    on_sale_until: string;
    cooking_minutes: string;
    target_food_cost_percent: string;
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
    lines: [],
    available_from: '',
    available_until: '',
    on_sale_from: '',
    on_sale_until: '',
    cooking_minutes: '',
    target_food_cost_percent: '',
});
const providerRows = ref<Record<string, ProviderChannelRow>>({});

// ---- Preview ---------------------------------------------------------
const rangeInStore = computed(() => comboPriceRange(form.base_price || '0', form.lines, items.value));
const rangeDelivery = computed(() => comboPriceRange(form.delivery_price || form.base_price || '0', form.lines, items.value));

// ---- LAUNCH review add-on: dates, cooking time ------------------------
const datesError = computed(() => (datesProblem(form.on_sale_from, form.on_sale_until) ? t('menu_extras.until_before_from') : null));
const cookingError = computed(() => (cookingProblem(form.cooking_minutes) ? t('menu_extras.cooking_range') : null));
const targetError = computed(() => (targetProblem(form.target_food_cost_percent) ? t('costs.food_cost.target_invalid') : null));
/** As saved: its food cost (users who see costs) and its allergens. */
const saved = ref<Combo | null>(null);
/** What customers see when the combo has no time of its own: its longest item. */
const itemsCookingFigure = computed(() => comboCookingFigure('', form.lines.flatMap((line) => (line.kind === 'fixed'
    ? [line.product_uuid, ...line.upgrades.map((u) => u.product_uuid)]
    : items.value.filter((i) => i.category_id === line.category_id).map((i) => i.uuid)))
    .map((uuid) => items.value.find((i) => i.uuid === uuid)?.cooking_minutes ?? null)));

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
    form.target_food_cost_percent = combo.target_food_cost_percent ?? '';
    saved.value = combo;
    form.lines = draftsFrom(combo.combo?.lines ?? [], nextKey);
    providerRows.value = providerRowsFrom(activeProviders.value, combo.delivery_provider_prices ?? []);
}

onMounted(async () => {
    loading.value = true;
    try {
        await Promise.all([
            listCategories().then((r) => { categories.value = r.data; }).catch(() => { categories.value = []; }),
            listAddonLinkOptions({ all: true }).then((r) => { items.value = r.data; }).catch(() => { items.value = []; }),
            listDeliveryProviders().then((r) => { providers.value = r.data; }).catch(() => { providers.value = []; }),
            listBranches().then((r) => { branches.value = r.data; }).catch(() => { branches.value = []; }),
        ]);
        if (isEdit) {
            prefill((await getCombo(editUuid!)).data);
        } else {
            form.lines = [{ key: nextKey('line'), id: null, kind: 'fixed', product_uuid: '', quantity: 1, upgrades: [] }];
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
        // as null, wiping them), the dates and the cooking time (fix order
        // C-1, L7: one pure, node-tested helper).
        ...comboMenuFields(form),
        target_food_cost_percent: targetPayload(form.target_food_cost_percent),
        ...(isEdit ? { status: form.status } : {}),
        lines: linesPayload(form.lines, items.value),
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
    if (form.lines.length === 0) problems.push(t('combos.lines.issues.no_lines'));
    form.lines.forEach((line, i) => {
        if (lineIssues(line, items.value).length > 0) problems.push(t('combos.lines.issues.line', { n: i + 1 }));
    });
    if (isUnrestricted.value && form.branch_scope === 'selected' && form.branch_ids.length === 0) problems.push(t('channels.pick_a_branch'));
    if (datesError.value) problems.push(datesError.value);
    if (cookingError.value) problems.push(cookingError.value);
    if (targetError.value) problems.push(targetError.value);
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
                    <!-- LAUNCH costs & allergens add-on — target food cost % and, as saved,
                         its food cost and allergens (every item, every choice). -->
                    <label class="block max-w-xs">
                        <span class="text-sm font-medium text-slate-700">{{ t('costs.food_cost.target_label') }}</span>
                        <input v-model="form.target_food_cost_percent" type="number" step="0.01" min="0.01" max="100" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="combo-target-food-cost">
                        <span class="mt-1 block text-xs text-slate-500">{{ t('costs.food_cost.target_hint') }}</span>
                        <span v-if="targetError || fieldError('target_food_cost_percent')" class="mt-1 block text-xs font-semibold text-rose-600">{{ targetError ?? fieldError('target_food_cost_percent') }}</span>
                    </label>
                    <p v-if="saved?.food_cost" class="text-sm" :class="foodCostTone(saved.food_cost) === 'over' ? 'font-semibold text-rose-700' : 'text-slate-700'" data-test="combo-food-cost">
                        {{ t('costs.food_cost.label') }}:
                        <template v-if="hasFoodCostPct(saved.food_cost)">{{ baisasText(saved.food_cost.cost_baisas) }} OMR · {{ t('costs.food_cost.summary', { pct: pctText(saved.food_cost.food_cost_pct), target: pctText(saved.food_cost.target_pct) }) }}<span v-if="saved.food_cost.status === 'incomplete'" class="ms-1 italic text-amber-700">({{ t('costs.food_cost.incomplete') }})</span></template>
                        <template v-else>{{ t(`costs.food_cost.${saved.food_cost.status}`) }}</template>
                    </p>
                    <div v-if="saved?.allergens" data-test="combo-allergens">
                        <p class="text-sm font-medium text-slate-700">{{ t('allergens.title') }}</p>
                        <AllergenChips :contains="saved.allergens.contains" :may-contain="saved.allergens.may_contain" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('allergens.combo_hint') }}</p>
                    </div>
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

                <!-- LAUNCH combo add-on — included items and choices. -->
                <ComboLinesEditor v-model="form.lines" :items="items" :categories="categories" :disabled="!canManage" :field-error="fieldError" :self-uuid="editUuid" />
                <p v-if="fieldError('lines')" class="text-xs text-rose-600">{{ fieldError('lines') }}</p>

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

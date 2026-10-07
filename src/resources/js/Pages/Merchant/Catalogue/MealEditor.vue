<script setup lang="ts">
/**
 * LAUNCH combo add-on (owner decision 4) — one meal setup: its name (shown
 * after the main's name: "Beef burger" + "meal"), the meal price added to the
 * main's own price, the categories whose products get "Make it a meal?"
 * (new products join automatically; the merchant unticks mains), the meal's
 * lines (included items with upgrades, choices) and optional dates.
 *
 * A main belongs to at most one active meal: the server refuses a save that
 * would make two active meals cover one product and names the clash.
 */
import { ArrowLeft, CalendarRange, Trash2, UtensilsCrossed } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import ComboLinesEditor from '@/Pages/Merchant/Catalogue/ComboLinesEditor.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createMeal,
    deleteMeal,
    getMeal,
    listAddonLinkOptions,
    listCategories,
    updateMeal,
    type AddonLinkOption,
    type Category,
    type Meal,
    type SaveMealPayload,
} from '@/lib/api/catalogue';
import { comboPriceRange, draftsFrom, lineIssues, linesPayload, mealMains, type LineDraft } from '@/lib/combo';
import { datesProblem, saleDay } from '@/lib/menuExtras';
import { MerchantPermission } from '@/lib/permissions';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.CatalogueManage));
const editUuid = route.name === 'merchant.catalogue.meal-edit' ? String(route.params.uuid) : null;
const isEdit = editUuid !== null;

const categories = ref<Category[]>([]);
const items = ref<AddonLinkOption[]>([]);

let seq = 0;
const nextKey = (p: string): string => `${p}-${++seq}`;

const form = reactive<{
    name: string;
    name_ar: string;
    meal_price: string;
    status: 'active' | 'inactive';
    on_sale_from: string;
    on_sale_until: string;
    category_ids: number[];
    excluded: string[];
    lines: LineDraft[];
}>({
    name: '',
    name_ar: '',
    meal_price: '',
    status: 'active',
    on_sale_from: '',
    on_sale_until: '',
    category_ids: [],
    excluded: [],
    lines: [],
});

const mains = computed(() => mealMains(form.category_ids, form.excluded, items.value));
const range = computed(() => comboPriceRange(form.meal_price || '0', form.lines, items.value));
const datesError = computed(() => (datesProblem(form.on_sale_from, form.on_sale_until) ? t('menu_extras.until_before_from') : null));

function toggleCategory(id: number, on: boolean): void {
    form.category_ids = on ? [...form.category_ids, id] : form.category_ids.filter((c) => c !== id);
}

function toggleMain(uuid: string, ticked: boolean): void {
    form.excluded = ticked ? form.excluded.filter((u) => u !== uuid) : [...form.excluded, uuid];
}

function nameOf(item: { name: string; name_ar?: string | null }): string {
    return locale.value === 'ar' && item.name_ar ? item.name_ar : item.name;
}

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

function prefill(meal: Meal): void {
    form.name = meal.name;
    form.name_ar = meal.name_ar ?? '';
    form.meal_price = meal.meal_price;
    form.status = meal.status;
    form.on_sale_from = meal.on_sale_from ?? '';
    form.on_sale_until = meal.on_sale_until ?? '';
    form.category_ids = [...meal.category_ids];
    form.excluded = [...meal.excluded_product_uuids];
    form.lines = draftsFrom(meal.lines, nextKey);
}

onMounted(async () => {
    try {
        await Promise.all([
            listCategories().then((r) => { categories.value = r.data; }),
            listAddonLinkOptions().then((r) => { items.value = r.data; }),
        ]);
        if (isEdit) {
            prefill((await getMeal(editUuid!)).data);
        } else {
            form.name = t('meals.default_name');
            form.name_ar = 'وجبة';
            form.lines = [{ key: nextKey('line'), id: null, kind: 'fixed', product_uuid: '', quantity: 1, upgrades: [] }];
        }
    } catch (err) {
        loadError.value = err instanceof ApiError && err.status === 404 ? t('meals.not_found') : t('meals.load_failed');
    } finally {
        loading.value = false;
    }
});

// ---- Save ----------------------------------------------------------
function payload(): SaveMealPayload {
    return {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        meal_price: String(form.meal_price).trim(),
        status: form.status,
        on_sale_from: saleDay(form.on_sale_from),
        on_sale_until: saleDay(form.on_sale_until),
        category_ids: form.category_ids,
        excluded_product_uuids: form.excluded.filter((uuid) => mains.value.some((m) => m.item.uuid === uuid)),
        lines: linesPayload(form.lines, items.value),
    };
}

const blockingProblems = computed<string[]>(() => {
    const problems: string[] = [];
    if (form.name.trim() === '') problems.push(t('meals.issues.name'));
    if (String(form.meal_price).trim() === '') problems.push(t('meals.issues.price'));
    if (form.category_ids.length === 0) problems.push(t('meals.issues.categories'));
    else if (!mains.value.some((m) => m.ticked)) problems.push(t('meals.issues.no_mains'));
    if (form.lines.length === 0) problems.push(t('combos.lines.issues.no_lines'));
    form.lines.forEach((line, i) => {
        if (lineIssues(line, items.value).length > 0) problems.push(t('combos.lines.issues.line', { n: i + 1 }));
    });
    if (datesError.value) problems.push(datesError.value);
    return problems;
});

async function save(): Promise<void> {
    if (!canManage.value || blockingProblems.value.length > 0) return;
    saving.value = true;
    saveError.value = null;
    fieldErrors.value = {};
    try {
        if (isEdit) {
            await updateMeal(editUuid!, payload());
        } else {
            await createMeal(payload());
        }
        void router.push('/catalogue/meals');
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            saveError.value = t('catalogue.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            saveError.value = String((err.payload as { message?: unknown }).message ?? t('meals.save_failed'));
        } else {
            saveError.value = t('meals.save_failed');
        }
        window.scrollTo({ top: 0 });
    } finally {
        saving.value = false;
    }
}

async function remove(): Promise<void> {
    if (!isEdit || !canManage.value || !window.confirm(t('meals.delete_confirm'))) return;
    try {
        await deleteMeal(editUuid!);
        void router.push('/catalogue/meals');
    } catch {
        saveError.value = t('meals.save_failed');
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl">
            <RouterLink to="/catalogue/meals" class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('meals.back') }}
            </RouterLink>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950">
                <UtensilsCrossed class="size-6 text-amber-600" />
                {{ isEdit ? t('meals.edit_title') : t('meals.create_title') }}
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ t('meals.editor_subtitle') }}</p>

            <div v-if="loading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="loadError" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ loadError }}</div>

            <form v-else class="mt-5" data-test="meal-form" @submit.prevent="save">
              <fieldset :disabled="!canManage" class="min-w-0 space-y-5">
                <div v-if="!canManage" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">{{ t('catalogue.wizard.readonly_hint') }}</div>
                <div v-if="saveError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ saveError }}</div>

                <!-- Basics -->
                <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('meals.name') }} *</span>
                            <input v-model="form.name" type="text" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="meal-name">
                            <span class="mt-1 block text-xs text-slate-500">{{ t('meals.name_hint') }}</span>
                            <span v-if="fieldError('name')" class="mt-1 block text-xs text-rose-600">{{ fieldError('name') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('meals.name_ar') }}</span>
                            <input v-model="form.name_ar" type="text" dir="rtl" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="meal-name-ar">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('meals.meal_price') }} (OMR) *</span>
                            <input v-model="form.meal_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="meal-price">
                            <span class="mt-1 block text-xs text-slate-500">{{ t('meals.meal_price_hint') }}</span>
                            <span v-if="fieldError('meal_price')" class="mt-1 block text-xs text-rose-600">{{ fieldError('meal_price') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('meals.status') }}</span>
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" data-test="meal-status">
                                <option value="active">{{ t('catalogue.statuses.active') }}</option>
                                <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                            </select>
                        </label>
                    </div>
                </section>

                <!-- Mains -->
                <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="meal-mains">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">{{ t('meals.mains_title') }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ t('meals.mains_hint') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="cat in categories" :key="cat.id" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm" data-test="meal-category">
                            <input type="checkbox" :checked="form.category_ids.includes(cat.id)" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" @change="toggleCategory(cat.id, ($event.target as HTMLInputElement).checked)">
                            {{ nameOf(cat) }}
                        </label>
                    </div>
                    <p v-if="fieldError('category_ids')" class="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700" data-test="meal-clash">{{ fieldError('category_ids') }}</p>
                    <ul v-if="mains.length > 0" class="grid gap-1 sm:grid-cols-2" data-test="meal-main-list">
                        <li v-for="main in mains" :key="main.item.uuid">
                            <label class="inline-flex items-center gap-1.5 text-sm" :class="main.ticked ? 'text-slate-800' : 'text-slate-400 line-through'">
                                <input type="checkbox" :checked="main.ticked" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="meal-main-tick" @change="toggleMain(main.item.uuid, ($event.target as HTMLInputElement).checked)">
                                {{ nameOf(main.item) }}<span v-if="main.item.base_price" class="text-xs text-slate-400">({{ main.item.base_price }})</span>
                            </label>
                        </li>
                    </ul>
                    <p class="text-[11px] text-slate-500">{{ t('meals.joins_hint') }}</p>
                </section>

                <!-- Lines -->
                <ComboLinesEditor v-model="form.lines" :items="items" :categories="categories" :disabled="!canManage" :field-error="fieldError" />
                <p v-if="fieldError('lines')" class="text-xs text-rose-600">{{ fieldError('lines') }}</p>

                <!-- Dates -->
                <section class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-test="meal-dates">
                    <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900"><CalendarRange class="size-4 text-indigo-600" /> {{ t('menu_extras.dates_title') }}</h2>
                    <div class="grid max-w-md grid-cols-2 gap-3">
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-600">{{ t('menu_extras.on_sale_from') }}</span>
                            <input v-model="form.on_sale_from" type="date" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" data-test="meal-sale-from">
                        </label>
                        <label class="block">
                            <span class="block text-xs font-medium text-slate-600">{{ t('menu_extras.on_sale_until') }}</span>
                            <input v-model="form.on_sale_until" type="date" :min="form.on_sale_from || undefined" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" data-test="meal-sale-until">
                        </label>
                    </div>
                    <p class="text-xs text-slate-500">{{ t('meals.dates_hint') }}</p>
                    <p v-if="datesError || fieldError('on_sale_until')" class="text-xs font-semibold text-rose-600">{{ datesError ?? fieldError('on_sale_until') }}</p>
                </section>

                <!-- Price preview -->
                <section class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-5 shadow-sm" data-test="meal-preview">
                    <h2 class="text-sm font-semibold text-indigo-900">{{ t('meals.preview_title') }}</h2>
                    <p class="mt-1 font-semibold tabular-nums text-indigo-950" dir="ltr">+{{ range.min }} – +{{ range.max }} OMR</p>
                    <p class="mt-1 text-xs text-indigo-700">{{ t('meals.preview_hint') }}</p>
                </section>

                <ul v-if="canManage && blockingProblems.length > 0" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800" data-test="meal-problems">
                    <li v-for="msg in blockingProblems" :key="msg">{{ msg }}</li>
                </ul>

                <div v-if="canManage" class="flex items-center justify-between pb-8">
                    <button v-if="isEdit" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50" data-test="meal-delete" @click="remove">
                        <Trash2 class="size-4" /> {{ t('meals.delete') }}
                    </button>
                    <span v-else />
                    <button type="submit" :disabled="saving || blockingProblems.length > 0" class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60" data-test="meal-save">
                        {{ saving ? t('catalogue.wizard.saving') : t('meals.save') }}
                    </button>
                </div>
              </fieldset>
            </form>
        </div>
    </MerchantLayout>
</template>

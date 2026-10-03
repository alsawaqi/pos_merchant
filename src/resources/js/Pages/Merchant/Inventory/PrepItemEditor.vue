<script setup lang="ts">
/**
 * LAUNCH-P3 P3-4 — create / edit a PREP ITEM (sauce, dough …).
 *
 * A name, a SMALL base unit (g, ml or piece), a yield (what one batch makes,
 * in that unit) and a recipe of ingredients or other prep items (at most 3
 * levels), each line typed with the recipe unit picker (P3-1). The live cost
 * per batch and per base unit follows the explode rule. Saving needs "Edit
 * recipes" (P3-3); without it the page is read-only. Every change is kept in
 * the history (P3-2) with an optional note. A prep item has no stock: it is
 * never received, counted or moved — its raw ingredients are.
 */
import { ArrowLeft, Beaker, Minus, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listIngredients, type Ingredient } from '@/lib/api/inventory';
import {
    createPrepItem,
    deletePrepItem,
    getPrepItem,
    getPrepItemHistory,
    updatePrepItem,
    type PrepItem,
    type PrepUnit,
} from '@/lib/api/prepItems';
import { canWriteRecipes } from '@/lib/permissions';
import {
    lineEntry,
    money,
    recipeLineProblem,
    recipeLinesHaveProblems,
    recipeUnitFactor,
    recipeUnitName,
    recipeUnitOptions,
    toBaseQuantity,
    wireRecipeUnit,
} from '@/lib/recipeUnits';
import RecipeHistoryPanel from '@/Pages/Merchant/Catalogue/RecipeHistoryPanel.vue';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { can } = usePermissions();

// Fix order 1, L8 — the one rule for every recipe write: "Edit recipes" + catalogue view.
const canEditRecipes = computed(() => canWriteRecipes(can));
const editUuid = route.name === 'merchant.prep-items.edit' ? String(route.params.uuid) : null;
const isEdit = editUuid !== null;

const prepUnits: PrepUnit[] = ['g', 'ml', 'piece'];

const ingredients = ref<Ingredient[]>([]);
const current = ref<PrepItem | null>(null);
const pageLoading = ref(true);
const pageError = ref<string | null>(null);
const saving = ref(false);
const saveError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});
const deleteOpen = ref(false);

const form = reactive<{
    name: string;
    name_ar: string;
    unit: PrepUnit;
    prep_yield_quantity: string;
    lines: { ingredient_uuid: string; quantity: string; unit: string }[];
    note: string;
}>({ name: '', name_ar: '', unit: 'g', prep_yield_quantity: '', lines: [], note: '' });

/** The components a prep recipe can use: every ingredient and every OTHER prep item. */
const pickable = computed(() => ingredients.value.filter((i) => i.uuid !== editUuid));
const rawIngredients = computed(() => pickable.value.filter((i) => !i.is_prep));
const prepIngredients = computed(() => pickable.value.filter((i) => i.is_prep));

function ingredientByUuid(uuid: string): Ingredient | null {
    return ingredients.value.find((i) => i.uuid === uuid) ?? null;
}

function addLine(): void {
    form.lines.push({ ingredient_uuid: '', quantity: '', unit: '' });
}

function removeLine(idx: number): void {
    form.lines.splice(idx, 1);
}

/**
 * P3-1 — an amount that rounds to 0 or would not record; fix order 1, L5 —
 * and a picked ingredient with a blank amount ("enter an amount or remove
 * this line"): it used to be dropped on save, deleting it from the recipe.
 */
function lineMessage(line: { ingredient_uuid: string; quantity: string; unit: string }): string | null {
    if (line.ingredient_uuid === '') return null;
    const problem = recipeLineProblem(ingredientByUuid(line.ingredient_uuid), line.unit, line.quantity, true, locale.value);
    return problem === null ? null : t(problem.key, problem.params);
}

/** Fix order 1, L4 — a line's amount ("150 g"), shown left-to-right. */
function lineAmount(line: { ingredient_uuid: string; quantity: string; unit: string }): string {
    return `${line.quantity} ${recipeUnitName(ingredientByUuid(line.ingredient_uuid), line.unit, locale.value)}`;
}

/** Exact-enough live cost of one batch: Σ quantity (in base units) × cost per base unit. */
const batchCost = computed<number>(() => {
    let total = 0;
    for (const line of form.lines) {
        const ingredient = ingredientByUuid(line.ingredient_uuid);
        if (!ingredient) continue;
        const base = toBaseQuantity(recipeUnitFactor(ingredient, line.unit), line.quantity);
        const cost = parseFloat(ingredient.default_unit_cost);
        if (base === null || !Number.isFinite(cost)) continue;
        total += base * cost;
    }
    return total;
});

const yieldNumber = computed<number | null>(() => {
    const n = parseFloat(String(form.prep_yield_quantity ?? '').trim());
    return Number.isFinite(n) && n > 0 ? n : null;
});

const unitCost = computed<string | null>(() => (yieldNumber.value === null ? null : (batchCost.value / yieldNumber.value).toFixed(6)));

/** "2000 ml = 2 l" — the yield is typed in the base unit; show the big unit too. */
const yieldHint = computed<string | null>(() => {
    if (yieldNumber.value === null || form.unit === 'piece') return null;
    const big = form.unit === 'g' ? 'kg' : 'l';
    return `${yieldNumber.value} ${form.unit} = ${+(yieldNumber.value / 1000).toFixed(4)} ${big}`;
});

const hasDuplicates = computed<boolean>(() => {
    const seen = new Set<string>();
    for (const line of form.lines) {
        if (!line.ingredient_uuid) continue;
        if (seen.has(line.ingredient_uuid)) return true;
        seen.add(line.ingredient_uuid);
    }
    return false;
});

const hasProblems = computed<boolean>(() => recipeLinesHaveProblems(form.lines, ingredientByUuid));

const completeLines = computed(() => form.lines.filter((l) => l.ingredient_uuid !== '' && String(l.quantity).trim() !== ''));

const canSave = computed<boolean>(() => canEditRecipes.value
    && form.name.trim() !== ''
    && yieldNumber.value !== null
    && completeLines.value.length > 0
    && !hasDuplicates.value
    && !hasProblems.value
    && !saving.value);

const unitLocked = computed<boolean>(() => {
    const used = current.value?.used_by;
    return isEdit && used !== null && used !== undefined && (used.product_recipes + used.addon_lines + used.prep_recipes) > 0;
});

function apiMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        const message = (err.payload as { message?: unknown }).message;
        if (typeof message === 'string' && message !== '') return message;
    }
    return err instanceof Error && err.message !== '' ? err.message : fallback;
}

async function save(): Promise<void> {
    if (!canSave.value) return;
    saving.value = true;
    saveError.value = null;
    fieldErrors.value = {};
    const payload = {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        unit: form.unit,
        prep_yield_quantity: String(form.prep_yield_quantity).trim(),
        lines: completeLines.value.map((l) => ({ ingredient_uuid: l.ingredient_uuid, quantity: String(l.quantity).trim(), unit: wireRecipeUnit(l.unit) })),
        note: form.note.trim() || null,
    };
    try {
        if (isEdit) {
            await updatePrepItem(editUuid!, payload);
        } else {
            await createPrepItem(payload);
        }
        void router.push({ path: '/inventory', query: { tab: 'prep_items' } });
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
        }
        saveError.value = apiMessage(err, t('prep_items.save_failed'));
        window.scrollTo({ top: 0 });
    } finally {
        saving.value = false;
    }
}

async function confirmDelete(): Promise<void> {
    if (!isEdit) return;
    saving.value = true;
    try {
        await deletePrepItem(editUuid!);
        deleteOpen.value = false;
        void router.push({ path: '/inventory', query: { tab: 'prep_items' } });
    } catch (err) {
        deleteOpen.value = false;
        saveError.value = apiMessage(err, t('prep_items.delete_failed'));
    } finally {
        saving.value = false;
    }
}

function firstError(key: string): string | null {
    for (const [k, messages] of Object.entries(fieldErrors.value)) {
        if ((k === key || k.startsWith(`${key}.`)) && messages.length > 0) return messages[0]!;
    }
    return null;
}

const loadHistory = () => getPrepItemHistory(editUuid!);

onMounted(async () => {
    try {
        const [list, item] = await Promise.all([
            listIngredients({ includePrep: true }),
            isEdit ? getPrepItem(editUuid!) : Promise.resolve(null),
        ]);
        ingredients.value = list.data;
        if (item) {
            current.value = item.data;
            form.name = item.data.name;
            form.name_ar = item.data.name_ar ?? '';
            form.unit = item.data.unit;
            form.prep_yield_quantity = item.data.prep_yield_quantity;
            // P3-1 — each line reopens exactly as typed.
            form.lines = (item.data.lines ?? []).map((line) => ({
                ingredient_uuid: line.ingredient?.uuid ?? '',
                ...lineEntry(line, line.ingredient?.unit),
            }));
        } else {
            addLine();
        }
    } catch (err) {
        pageError.value = err instanceof ApiError && err.status === 404 ? t('prep_items.not_found') : apiMessage(err, t('prep_items.load_failed'));
    } finally {
        pageLoading.value = false;
    }
});
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl space-y-4">
            <RouterLink :to="{ path: '/inventory', query: { tab: 'prep_items' } }" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
                <ArrowLeft class="size-3.5" />
                {{ t('prep_items.back') }}
            </RouterLink>
            <h1 class="text-2xl font-bold text-slate-950">{{ isEdit ? t('prep_items.edit_title') : t('prep_items.create_title') }}</h1>
            <p class="max-w-3xl text-sm text-slate-500">{{ t('prep_items.editor_hint') }}</p>

            <div v-if="pageLoading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
            <div v-else-if="pageError" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ pageError }}</div>
            <template v-else>
                <p v-if="!canEditRecipes" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800" data-test="prep-readonly">{{ t('recipe_permission.readonly_hint') }}</p>
                <p v-if="saveError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ saveError }}</p>

                <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('prep_items.fields.name') }}</span>
                        <input v-model="form.name" type="text" maxlength="191" :disabled="!canEditRecipes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                        <span v-if="firstError('name')" class="mt-1 block text-xs text-rose-600">{{ firstError('name') }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('prep_items.fields.name_ar') }}</span>
                        <input v-model="form.name_ar" type="text" dir="rtl" maxlength="191" :disabled="!canEditRecipes" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('prep_items.fields.unit') }}</span>
                        <select v-model="form.unit" :disabled="!canEditRecipes || unitLocked" data-test="prep-unit" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                            <option v-for="u in prepUnits" :key="u" :value="u">{{ t(`prep_items.units.${u}`) }}</option>
                        </select>
                        <span class="mt-1 block text-xs text-slate-500">{{ unitLocked ? t('prep_items.unit_locked') : t('prep_items.unit_hint') }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('prep_items.fields.yield') }}</span>
                        <div class="mt-1 flex items-center gap-2">
                            <input v-model="form.prep_yield_quantity" type="number" step="0.0001" min="0" :disabled="!canEditRecipes" data-test="prep-yield" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                            <span class="text-sm font-semibold text-slate-600">{{ form.unit }}</span>
                        </div>
                        <span class="mt-1 block text-xs text-slate-500">{{ yieldHint ?? t('prep_items.yield_hint') }}</span>
                        <span v-if="firstError('prep_yield_quantity')" class="mt-1 block text-xs text-rose-600">{{ firstError('prep_yield_quantity') }}</span>
                    </label>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <Beaker class="size-4 text-amber-600" />
                        {{ t('prep_items.recipe_title') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ t('prep_items.recipe_hint') }}</p>
                    <p v-if="firstError('lines')" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ firstError('lines') }}</p>

                    <ul v-if="!canEditRecipes" class="mt-3 space-y-1">
                        <!-- Fix order 1, L4 — the amount is isolated left-to-right so it never garbles in Arabic. -->
                        <li v-for="(line, idx) in form.lines" :key="idx" class="text-sm text-slate-700">
                            <bdi dir="ltr" class="tabular-nums">{{ lineAmount(line) }}</bdi> {{ ingredientByUuid(line.ingredient_uuid)?.name ?? '—' }}
                        </li>
                    </ul>
                    <template v-else>
                        <ul class="mt-3 space-y-2">
                            <li v-for="(line, idx) in form.lines" :key="idx" class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2">
                                <label class="block min-w-[14rem] flex-1">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.fields.ingredient') }}</span>
                                    <select v-model="line.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" @change="line.unit = ''">
                                        <option value="">{{ t('catalogue.recipe.pick_ingredient') }}</option>
                                        <option v-for="ing in rawIngredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }} ({{ ing.unit }})</option>
                                        <optgroup v-if="prepIngredients.length > 0" :label="t('prep_items.optgroup')">
                                            <option v-for="ing in prepIngredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }} ({{ ing.unit }})</option>
                                        </optgroup>
                                    </select>
                                </label>
                                <label class="block w-28">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.quantity') }}</span>
                                    <input v-model="line.quantity" type="number" step="0.0001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </label>
                                <label class="block w-36">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.unit') }}</span>
                                    <select v-model="line.unit" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option v-for="u in recipeUnitOptions(ingredientByUuid(line.ingredient_uuid), locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                    </select>
                                </label>
                                <button type="button" class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50" :title="t('catalogue.recipe.remove_line')" @click="removeLine(idx)">
                                    <Minus class="size-4" />
                                </button>
                                <p v-if="lineMessage(line)" class="basis-full text-xs font-semibold text-rose-700" data-test="prep-line-problem">{{ lineMessage(line) }}</p>
                            </li>
                        </ul>
                        <button type="button" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100" @click="addLine">
                            <Plus class="size-3.5" />
                            {{ t('catalogue.recipe.add_line') }}
                        </button>
                        <p v-if="hasDuplicates" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ t('catalogue.recipe.duplicate_ingredient') }}</p>
                    </template>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2" data-test="prep-live-cost">
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ t('prep_items.batch_cost') }}</p>
                            <p class="text-base font-semibold tabular-nums text-amber-900">{{ money(batchCost) }} <span class="text-[10px] font-normal text-amber-600">OMR</span></p>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ t('prep_items.unit_cost', { unit: form.unit }) }}</p>
                            <p class="text-base font-semibold tabular-nums text-amber-900">{{ unitCost ?? '—' }} <span class="text-[10px] font-normal text-amber-600">OMR</span></p>
                        </div>
                    </div>

                    <label v-if="canEditRecipes" class="mt-3 block">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('recipe_history.note_field') }}</span>
                        <input v-model="form.note" type="text" maxlength="1000" :placeholder="t('recipe_history.note_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </label>
                </section>

                <RecipeHistoryPanel v-if="isEdit" :load="loadHistory" :yield-unit="form.unit" />

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <button
                        v-if="isEdit && canEditRecipes"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50"
                        @click="deleteOpen = true"
                    >
                        <Trash2 class="size-4" /> {{ t('prep_items.delete') }}
                    </button>
                    <span v-else />
                    <button
                        v-if="canEditRecipes"
                        type="button"
                        :disabled="!canSave"
                        data-test="prep-save"
                        class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="save"
                    >
                        {{ saving ? t('common.saving') : t('common.save') }}
                    </button>
                </div>
            </template>
        </div>

        <BaseModal v-if="deleteOpen" size="md" @close="deleteOpen = false">
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-semibold text-slate-950">{{ t('prep_items.delete_title') }}</h2>
                <p class="text-sm text-slate-600">{{ t('prep_items.delete_body', { name: form.name }) }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="deleteOpen = false">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="saving" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700" @click="confirmDelete">{{ t('prep_items.delete') }}</button>
                </div>
            </div>
        </BaseModal>
    </MerchantLayout>
</template>

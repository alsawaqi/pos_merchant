<script setup lang="ts">
// PD3b — per-option stock-usage line editor, shared by the product
// wizard's owned-group option forms and the Add-ons tab option modal.
// Each line: direction (uses / removes) + ingredient XOR item + qty
// (+ unit for ingredient lines: the ingredient's base, its metric pair, an
// extra unit or — LAUNCH-P3 P3-1 — its piece unit; the server converts-at-
// entry, stores base AND the entered unit, and refuses an amount that rounds
// to 0). LAUNCH-P3: prep items are picked like ingredients (P3-4), and
// without "Edit recipes" (P3-3) the lines are shown read-only.
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import type { ComponentOption, ConsumptionLinePayload } from '@/lib/api/catalogue';
import type { Ingredient } from '@/lib/api/inventory';
import { consumptionLineProblem, lineAmountText, recipeUnitFactor, recipeUnitOptions } from '@/lib/recipeUnits';
// LAUNCH review add-on (step 11) — E1 the live translation, E2 "Is this right?".
import { recipeLineWarning, translateAmount } from '@/lib/amountSafety';

const props = defineProps<{
    modelValue: ConsumptionLinePayload[];
    ingredients: Ingredient[];
    products: ComponentOption[];
    disabled?: boolean;
    /** LAUNCH-P3 P3-3 — no "Edit recipes": show the lines, change nothing. */
    readonly?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', lines: ConsumptionLinePayload[]): void }>();

const { t, locale } = useI18n();

function replaceAt(idx: number, line: ConsumptionLinePayload): void {
    const next = props.modelValue.slice();
    next[idx] = line;
    emit('update:modelValue', next);
}

function patch(idx: number, partial: Partial<ConsumptionLinePayload>): void {
    const current = props.modelValue[idx];
    if (!current) return;
    replaceAt(idx, { ...current, ...partial });
}

function onTypeChange(idx: number, type: 'ingredient' | 'product'): void {
    const current = props.modelValue[idx];
    if (!current) return;
    // Switching kind drops the old ref + unit, keeps direction + qty.
    replaceAt(idx, { type, direction: current.direction, quantity: current.quantity, ingredient_uuid: '', product_uuid: '', unit: '' });
}

function addLine(): void {
    emit('update:modelValue', [
        ...props.modelValue,
        { type: 'ingredient', direction: 'add', ingredient_uuid: '', product_uuid: '', quantity: '', unit: '' },
    ]);
}

function removeLine(idx: number): void {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== idx));
}

/** Stored refs missing from the picker lists (re-purposed items, filtered
 * kinds, past the list cap) — kept visible + selected instead of rendering
 * a blank select (the components section's "(attached)" pattern). */
const extraIngredientRefs = computed(() => {
    const seen = new Set<string>();
    const out: { uuid: string; label: string }[] = [];
    for (const line of props.modelValue) {
        const uuid = line.type === 'ingredient' ? (line.ingredient_uuid ?? '') : '';
        if (uuid === '' || seen.has(uuid) || props.ingredients.some((i) => i.uuid === uuid)) continue;
        seen.add(uuid);
        out.push({ uuid, label: `${line.ingredient_label ?? '#'} (${t('catalogue.consumption.attached')})` });
    }
    return out;
});

const extraProductRefs = computed(() => {
    const seen = new Set<string>();
    const out: { uuid: string; label: string }[] = [];
    for (const line of props.modelValue) {
        const uuid = line.type === 'product' ? (line.product_uuid ?? '') : '';
        if (uuid === '' || seen.has(uuid) || props.products.some((p) => p.uuid === uuid)) continue;
        seen.add(uuid);
        out.push({ uuid, label: `${line.product_label ?? '#'} (${t('catalogue.consumption.attached')})` });
    }
    return out;
});

/** Base + extra units + metric pair + (LAUNCH-P3) the piece unit for the
 * picked ingredient; the base option is relabelled "(base)". */
function unitsFor(ingredientUuid: string | undefined): { value: string; label: string }[] {
    const ingredient = props.ingredients.find((i) => i.uuid === ingredientUuid);
    if (!ingredient) return [];
    return recipeUnitOptions(ingredient, locale.value).map((u) =>
        u.value === '' ? { value: '', label: `${u.label} (${t('catalogue.consumption.base_unit')})` } : { value: u.value, label: u.label });
}

const rawIngredients = computed(() => props.ingredients.filter((i) => !i.is_prep));
const prepIngredients = computed(() => props.ingredients.filter((i) => i.is_prep));

/**
 * LAUNCH-P3 P3-1 — an amount that would round to 0 (or more than 4 decimals),
 * per line. Fix order 1, L5 + K8 — and a picked ingredient or item with a
 * blank / 0 amount; the option's save is blocked while any line has one.
 */
function lineProblem(line: ConsumptionLinePayload): string | null {
    const problem = consumptionLineProblem(line, (uuid) => props.ingredients.find((i) => i.uuid === uuid), locale.value);
    return problem === null ? null : t(problem.key, problem.params);
}

/** The ingredient an ingredient line picked, or undefined. */
function lineIngredient(line: ConsumptionLinePayload): Ingredient | undefined {
    return line.type === 'ingredient' ? props.ingredients.find((i) => i.uuid === line.ingredient_uuid) : undefined;
}

/** Step 11, E1 — "= 2 l 500 ml" (a container: "= 36 × bottle 1 l = 36 l") under an ingredient line. */
function lineTranslation(line: ConsumptionLinePayload): string {
    const ingredient = lineIngredient(line);
    if (!ingredient) return '';
    const parts = translateAmount({ amount: line.quantity, unit: line.unit ?? '', storedUnit: ingredient.unit, containers: ingredient.alt_units ?? [], locale: locale.value });
    return parts.length > 0 ? t('amount_safety.translation', { text: parts.join(' = ') }) : '';
}

/**
 * Step 11, E2 — one option using more than 2 kg / 2 l / 50 pieces is said
 * under its line, as a warning that never blocks (the product wizard also
 * asks "Is this right?" when it saves).
 */
function lineWarning(line: ConsumptionLinePayload): string | null {
    const ingredient = lineIngredient(line);
    if (!ingredient) return null;
    const warning = recipeLineWarning({ amount: line.quantity, unit: line.unit ?? '', storedUnit: ingredient.unit, factor: recipeUnitFactor(ingredient, line.unit ?? '') });
    return warning === null ? null : t(warning.key, warning.params);
}

/** Read-only rendering of one line: "Uses", the amount ("9 g", fix order 1 L4: shown left-to-right) and the name. */
function readonlyParts(line: ConsumptionLinePayload): { direction: string; amount: string; name: string } {
    const direction = line.direction === 'remove' ? t('catalogue.consumption.removes') : t('catalogue.consumption.uses');
    if (line.type === 'ingredient') {
        const ingredient = props.ingredients.find((i) => i.uuid === line.ingredient_uuid);
        // LAUNCH item kind, A8 — "1.5 kg", not "1500 g", when in the stored unit.
        return { direction, amount: lineAmountText(ingredient, line.unit ?? '', line.quantity, locale.value), name: ingredient?.name ?? line.ingredient_label ?? '—' };
    }
    const product = props.products.find((p) => p.uuid === line.product_uuid);
    return { direction, amount: `${line.quantity} ×`, name: product?.name ?? line.product_label ?? '—' };
}

function productLabel(option: ComponentOption): string {
    return option.stock_mode === 'cooked'
        ? `${option.name} (${t('catalogue.consumption.prepared')})`
        : option.name;
}
</script>

<template>
    <!-- LAUNCH-P3 P3-3 — read-only without "Edit recipes". -->
    <div v-if="readonly" class="space-y-1" data-test="consumption-readonly">
        <p v-if="modelValue.length === 0" class="text-xs italic text-slate-500">{{ t('catalogue.consumption.none') }}</p>
        <p v-for="(line, idx) in modelValue" :key="idx" class="text-xs text-slate-700">{{ readonlyParts(line).direction }} <bdi dir="ltr" class="tabular-nums">{{ readonlyParts(line).amount }}</bdi> {{ readonlyParts(line).name }}</p>
        <p class="text-[11px] text-amber-700">{{ t('recipe_permission.readonly_hint') }}</p>
    </div>
    <div v-else class="space-y-2">
        <div
            v-for="(line, idx) in modelValue"
            :key="idx"
            class="grid items-end gap-2 rounded-lg border border-slate-100 bg-slate-50/60 p-2 sm:grid-cols-[6.5rem_6.5rem_1fr_5.5rem_6rem_auto]"
        >
            <label class="block">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.consumption.direction') }}</span>
                <select
                    :value="line.direction"
                    :disabled="disabled"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    @change="patch(idx, { direction: ($event.target as HTMLSelectElement).value as 'add' | 'remove' })"
                >
                    <option value="add">{{ t('catalogue.consumption.uses') }}</option>
                    <option value="remove">{{ t('catalogue.consumption.removes') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.consumption.kind') }}</span>
                <select
                    :value="line.type"
                    :disabled="disabled"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    @change="onTypeChange(idx, ($event.target as HTMLSelectElement).value as 'ingredient' | 'product')"
                >
                    <option value="ingredient">{{ t('catalogue.consumption.kind_ingredient') }}</option>
                    <option value="product">{{ t('catalogue.consumption.kind_item') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    {{ line.type === 'ingredient' ? t('catalogue.consumption.ingredient') : t('catalogue.consumption.item') }}
                </span>
                <select
                    v-if="line.type === 'ingredient'"
                    :value="line.ingredient_uuid ?? ''"
                    :disabled="disabled"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    @change="patch(idx, { ingredient_uuid: ($event.target as HTMLSelectElement).value, unit: '' })"
                >
                    <option value="">—</option>
                    <option v-for="ing in rawIngredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }}</option>
                    <!-- LAUNCH-P3 P3-4 — prep items are used like ingredients. -->
                    <optgroup v-if="prepIngredients.length > 0" :label="t('prep_items.optgroup')">
                        <option v-for="ing in prepIngredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }}</option>
                    </optgroup>
                    <!-- A stored ref missing from the picker (re-purposed /
                         filtered / past the cap) stays visible + selected. -->
                    <option v-for="extra in extraIngredientRefs" :key="extra.uuid" :value="extra.uuid">{{ extra.label }}</option>
                </select>
                <select
                    v-else
                    :value="line.product_uuid ?? ''"
                    :disabled="disabled"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    @change="patch(idx, { product_uuid: ($event.target as HTMLSelectElement).value })"
                >
                    <option value="">—</option>
                    <option v-for="opt in products" :key="opt.uuid" :value="opt.uuid">{{ productLabel(opt) }}</option>
                    <option v-for="extra in extraProductRefs" :key="extra.uuid" :value="extra.uuid">{{ extra.label }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.consumption.quantity') }}</span>
                <input
                    :value="line.quantity"
                    :disabled="disabled"
                    type="number"
                    step="0.0001"
                    min="0"
                    data-test="consumption-quantity"
                    class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                    @input="patch(idx, { quantity: ($event.target as HTMLInputElement).value })"
                >
            </label>
            <label class="block">
                <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.consumption.unit') }}</span>
                <select
                    v-if="line.type === 'ingredient'"
                    :value="line.unit ?? ''"
                    :disabled="disabled || !line.ingredient_uuid"
                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50"
                    @change="patch(idx, { unit: ($event.target as HTMLSelectElement).value })"
                >
                    <option v-for="u in unitsFor(line.ingredient_uuid)" :key="u.value" :value="u.value">{{ u.label }}</option>
                </select>
                <span v-else class="mt-1 block rounded-lg border border-transparent px-2 py-1.5 text-xs text-slate-400">{{ t('catalogue.consumption.pieces') }}</span>
            </label>
            <button
                type="button"
                :disabled="disabled"
                class="mb-0.5 rounded p-1.5 text-rose-500 transition hover:bg-rose-100 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                :title="t('common.delete')"
                @click="removeLine(idx)"
            >
                <Trash2 class="size-3.5" />
            </button>
            <p v-if="lineProblem(line)" class="col-span-full text-[11px] font-semibold text-rose-700" data-test="consumption-line-problem">{{ lineProblem(line) }}</p>
            <!-- Step 11 — E1 the translation, E2 the warning (never blocks). -->
            <p v-if="lineTranslation(line)" class="col-span-full text-[11px] font-medium text-teal-700" data-test="consumption-translation"><bdi dir="ltr">{{ lineTranslation(line) }}</bdi></p>
            <p v-if="lineWarning(line)" class="col-span-full text-[11px] font-semibold text-amber-700" data-test="consumption-warning">{{ lineWarning(line) }}</p>
        </div>

        <button
            type="button"
            :disabled="disabled"
            class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            @click="addLine"
        >
            <Plus class="size-3" /> {{ t('catalogue.consumption.add_line') }}
        </button>
    </div>
</template>

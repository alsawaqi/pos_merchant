<script setup lang="ts">
// PD3b — per-option stock-usage line editor, shared by the product
// wizard's owned-group option forms and the Add-ons tab option modal.
// Each line: direction (uses / removes) + ingredient XOR item + qty
// (+ unit for ingredient lines: the ingredient's base or an alt unit;
// the server converts-at-entry and stores base).
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import type { ComponentOption, ConsumptionLinePayload } from '@/lib/api/catalogue';
import type { Ingredient } from '@/lib/api/inventory';

const props = defineProps<{
    modelValue: ConsumptionLinePayload[];
    ingredients: Ingredient[];
    products: ComponentOption[];
    disabled?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', lines: ConsumptionLinePayload[]): void }>();

const { t } = useI18n();

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

/** Base + alternate unit names for the picked ingredient. */
function unitsFor(ingredientUuid: string | undefined): { value: string; label: string }[] {
    const ingredient = props.ingredients.find((i) => i.uuid === ingredientUuid);
    if (!ingredient) return [];
    const units = [{ value: '', label: `${ingredient.unit} (${t('catalogue.consumption.base_unit')})` }];
    for (const alt of ingredient.alt_units ?? []) {
        units.push({ value: alt.name, label: alt.name });
    }
    return units;
}

function productLabel(option: ComponentOption): string {
    return option.stock_mode === 'cooked'
        ? `${option.name} (${t('catalogue.consumption.prepared')})`
        : option.name;
}
</script>

<template>
    <div class="space-y-2">
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
                    <option v-for="ing in ingredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }}</option>
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
                    step="0.001"
                    min="0.001"
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

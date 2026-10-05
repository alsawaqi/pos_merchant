<script setup lang="ts">
/**
 * LAUNCH review add-on (D1–D4) — one item's amount BY CONTAINER: container
 * rows ("3 × bottle 1.5 l", "5 × bottle 500 ml") and a total that fills in
 * as Σ pieces × size. The total may be LOWERED (a half-used bottle: 3
 * bottles = 4 l) but never RAISED — the box turns red and the screen blocks
 * the save. Used by transfers, day-end counts (several containers, 0 allowed,
 * never pre-filled: counts stay blind), waste and restock requests (one row),
 * and the warehouse dialog's Allocate / Transfer (fix order B-2).
 *
 * Fix order B-2 (the owner's broken bottle) — a NESTED container row (2 ×
 * crate of 12 × bottle 1 l) shows its inner count "= 24 × bottle 1 l" as a
 * box that defaults to the full count and may be lowered (23: one broken),
 * never raised; the total follows it.
 */
import { Minus, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { amountInStored, amountProblem, containerLabel, containersOf, friendly, innerCount, rowsCap, type ContainerHolder } from '@/lib/containers';
import { kindUnits, unitOptionLabel } from '@/lib/itemKind';
import AmountInput from './AmountInput.vue';

export interface ContainerRowDraft {
    container_uuid: string;
    pieces: string | number;
    /** Fix order B-2 — a nested container's inner count, lowered ('' = the full count). */
    leaf_pieces?: string | number;
}

const props = withDefaults(defineProps<{
    ingredient: ContainerHolder & { name?: string };
    rows: ContainerRowDraft[];
    amount: string | number;
    amountUnit: string;
    single?: boolean;
    allowZero?: boolean;
    /** Show the inner count of nested containers (not where the server takes none). */
    inner?: boolean;
}>(), { single: false, allowZero: false, inner: true });

const emit = defineEmits<{
    (e: 'update:rows', rows: ContainerRowDraft[]): void;
    (e: 'update:amount', amount: string): void;
    (e: 'update:amountUnit', unit: string): void;
}>();

const { t, locale } = useI18n();

const containers = computed(() => containersOf(props.ingredient));
const storedUnit = computed(() => props.ingredient.unit);
const cap = computed(() => rowsCap(props.ingredient, props.rows.filter((r) => r.container_uuid !== '').map((r) => (props.inner ? r : { ...r, leaf_pieces: '' }))));
const typed = computed(() => amountInStored(props.amount, props.amountUnit, storedUnit.value));
const problem = computed(() => amountProblem(typed.value, cap.value, props.allowZero));

const unitOptions = computed(() => {
    const units = kindUnits(storedUnit.value).filter((u) => u.value !== storedUnit.value);
    return [{ value: '', label: storedUnit.value }, ...units.map((u) => ({ value: u.value, label: unitOptionLabel(u.value, locale.value) }))];
});

/** The inner count of a nested row (null for a container that holds an amount). */
function inner(row: ContainerRowDraft): ReturnType<typeof innerCount> {
    return props.inner ? innerCount(props.ingredient, row) : null;
}

function update(index: number, patch: Partial<ContainerRowDraft>): void {
    // Another container or another number of them starts the inner count again (full).
    const reset = patch.container_uuid !== undefined || patch.pieces !== undefined ? { leaf_pieces: '' } : {};
    emit('update:rows', props.rows.map((r, i) => (i === index ? { ...r, ...reset, ...patch } : r)));
}

function addRow(): void {
    emit('update:rows', [...props.rows, { container_uuid: containers.value[0]?.uuid ?? '', pieces: '' }]);
}

function removeRow(index: number): void {
    emit('update:rows', props.rows.filter((_, i) => i !== index));
}
</script>

<template>
    <div class="space-y-2" data-test="container-rows">
        <div v-for="(row, i) in rows" :key="i" class="flex flex-wrap items-center gap-2">
            <input
                :value="row.pieces"
                type="number"
                step="any"
                :min="allowZero ? '0' : '0.0001'"
                :placeholder="t('containers.pieces')"
                class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-sm tabular-nums"
                data-test="container-pieces"
                @input="update(i, { pieces: ($event.target as HTMLInputElement).value })"
            >
            <span class="text-sm text-slate-500">×</span>
            <select
                :value="row.container_uuid"
                class="min-w-[10rem] flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm"
                data-test="container-select"
                @change="update(i, { container_uuid: ($event.target as HTMLSelectElement).value })"
            >
                <option v-for="c in containers" :key="c.uuid" :value="c.uuid">{{ containerLabel(c, locale, storedUnit) }}</option>
            </select>
            <button v-if="!single" type="button" class="grid size-8 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50" :title="t('containers.remove')" @click="removeRow(i)">
                <Minus class="size-3.5" />
            </button>
            <template v-if="inner(row) !== null && inner(row)!.full > 0">
                <div class="flex w-full flex-wrap items-center gap-2 ps-4" data-test="container-inner">
                    <span class="text-sm text-slate-500">=</span>
                    <input
                        :value="row.leaf_pieces ?? ''"
                        type="number"
                        step="any"
                        min="0"
                        :placeholder="String(inner(row)!.full)"
                        :class="`w-20 rounded-lg border px-2 py-1.5 text-sm tabular-nums ${inner(row)!.raised ? 'border-rose-400 bg-rose-50 text-rose-700' : 'border-slate-200'}`"
                        data-test="container-inner-pieces"
                        @input="update(i, { leaf_pieces: ($event.target as HTMLInputElement).value })"
                    >
                    <span class="text-sm text-slate-600">× {{ containerLabel(inner(row)!.leaf, locale, storedUnit) }}</span>
                    <span class="text-[11px] text-slate-500">{{ t('containers.inner_hint', { full: inner(row)!.full }) }}</span>
                    <p v-if="inner(row)!.raised" class="w-full text-[11px] font-semibold text-rose-600" data-test="container-inner-raised">
                        {{ t('containers.inner_raised', { full: inner(row)!.full, leaf: containerLabel(inner(row)!.leaf, locale, storedUnit) }) }}
                    </p>
                </div>
            </template>
        </div>
        <button v-if="!single && containers.length > 0" type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" data-test="add-container-row" @click="addRow">
            <Plus class="size-3.5" /> {{ t('containers.add_row') }}
        </button>
        <div v-if="rows.length > 0" class="max-w-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.total') }}</span>
            <AmountInput
                :model-value="amount"
                :unit="amountUnit"
                :options="unitOptions"
                :stored-unit="storedUnit"
                :placeholder="cap > 0 ? friendly(cap, storedUnit) : ''"
                :input-class="`w-full rounded-lg border px-2.5 py-1.5 text-sm tabular-nums ${problem === 'raised' ? 'border-rose-400 bg-rose-50 text-rose-700' : 'border-slate-200'}`"
                select-class="shrink-0 rounded-lg border border-slate-200 px-2 py-1.5 text-sm"
                data-test="container-total"
                @update:model-value="emit('update:amount', $event)"
                @update:unit="emit('update:amountUnit', $event)"
            />
            <p class="mt-0.5 text-[11px] text-slate-500">{{ t('containers.total_hint', { cap: friendly(cap, storedUnit) }) }}</p>
            <p v-if="problem === 'raised'" class="mt-0.5 text-[11px] font-semibold text-rose-600" data-test="container-total-raised">{{ t('containers.total_raised', { cap: friendly(cap, storedUnit) }) }}</p>        </div>
    </div>
</template>

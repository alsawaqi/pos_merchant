<script setup lang="ts">
/**
 * LAUNCH review add-on (D1–D4) — one item's amount BY CONTAINER: container
 * rows ("3 × bottle 1.5 l", "5 × bottle 500 ml") and a total that fills in
 * as Σ pieces × size. The total may be LOWERED (a half-used bottle: 3
 * bottles = 4 l) but never RAISED — the box turns red and the screen blocks
 * the save. Used by transfers, day-end counts (several containers, 0 allowed,
 * never pre-filled: counts stay blind), waste and restock requests (one row).
 */
import { Minus, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { amountInStored, amountProblem, containerLabel, containersOf, friendly, rowsCap, type ContainerHolder } from '@/lib/containers';
import { kindUnits, unitOptionLabel } from '@/lib/itemKind';
import AmountInput from './AmountInput.vue';

export interface ContainerRowDraft {
    container_uuid: string;
    pieces: string | number;
}

const props = withDefaults(defineProps<{
    ingredient: ContainerHolder & { name?: string };
    rows: ContainerRowDraft[];
    amount: string | number;
    amountUnit: string;
    single?: boolean;
    allowZero?: boolean;
}>(), { single: false, allowZero: false });

const emit = defineEmits<{
    (e: 'update:rows', rows: ContainerRowDraft[]): void;
    (e: 'update:amount', amount: string): void;
    (e: 'update:amountUnit', unit: string): void;
}>();

const { t, locale } = useI18n();

const containers = computed(() => containersOf(props.ingredient));
const storedUnit = computed(() => props.ingredient.unit);
const cap = computed(() => rowsCap(props.ingredient, props.rows.filter((r) => r.container_uuid !== '')));
const typed = computed(() => amountInStored(props.amount, props.amountUnit, storedUnit.value));
const problem = computed(() => amountProblem(typed.value, cap.value, props.allowZero));

const unitOptions = computed(() => {
    const units = kindUnits(storedUnit.value).filter((u) => u.value !== storedUnit.value);
    return [{ value: '', label: storedUnit.value }, ...units.map((u) => ({ value: u.value, label: unitOptionLabel(u.value, locale.value) }))];
});

function update(index: number, patch: Partial<ContainerRowDraft>): void {
    emit('update:rows', props.rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
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
            <p v-if="problem === 'raised'" class="mt-0.5 text-[11px] font-semibold text-rose-600" data-test="container-total-raised">{{ t('containers.total_raised', { cap: friendly(cap, storedUnit) }) }}</p>
        </div>
    </div>
</template>

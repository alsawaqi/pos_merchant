<script setup lang="ts">
/**
 * LAUNCH review add-on (A2, A3, A5) — "Containers — how do you buy it?".
 *
 * As many rows as needed, each a name (+ Arabic name) and what ONE holds:
 * an amount of the item ("bottle holds 1.5 l") or N of another container of
 * the same item ("crate holds 12 × bottle 1 l"). The same word may be used
 * with different sizes; the size is part of the shown name. One row may be
 * marked "Tills count in this" — the tills and handhelds count the item in
 * it (A3; it replaces the old "Count container" field). Each row carries its
 * barcodes (several per container: other brands of the same size).
 *
 *   mode "create"  rows are drafts (v-model) sent with the new ingredient;
 *   mode "edit"    rows save on their own through the container endpoints;
 *                  a used container's size is LOCKED (add a new one instead),
 *                  its names stay editable.
 */
import { Barcode, Check, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import {
    createIngredientUnit,
    deleteIngredientUnit,
    listIngredientUnits,
    updateIngredient,
    updateIngredientUnit,
    type IngredientAltUnit,
} from '@/lib/api/inventory';
import { barcodeConflictOf, createBarcode, deleteBarcode, moveBarcodeHere } from '@/lib/api/inventoryCodes';
import { containerSizeWarning } from '@/lib/amountSafety';
import { friendlyAmount, holdsEntry, kindOfUnit, kindUnits, toStoredAmount, unitOptionLabel } from '@/lib/itemKind';
import BarcodeChips from './BarcodeChips.vue';

export interface ContainerDraft {
    key: number;
    name: string;
    name_ar: string;
    /** 'amount' = holds an amount of the item; 'nested' = holds N of another row. */
    mode: 'amount' | 'nested';
    amount: string | number;
    unit: string;
    /** Nested: the index of an EARLIER draft it holds. */
    contains_index: number | null;
    contains_quantity: string | number;
    count_container: boolean;
    barcodes: string[];
}

const props = withDefaults(defineProps<{
    mode: 'create' | 'edit';
    /** The item's stored unit (the kind's units are offered from it). */
    storedUnit: string;
    drafts?: ContainerDraft[];
    ingredientUuid?: string | null;
    countContainerUuid?: string | null;
    canManage?: boolean;
    /** Edit mode: the item's whole / part containers setting (saved with the count container). */
    allowFractional?: boolean;
}>(), { drafts: () => [], ingredientUuid: null, countContainerUuid: null, canManage: true, allowFractional: true });

const emit = defineEmits<{
    (e: 'update:drafts', drafts: ContainerDraft[]): void;
    /** Edit mode: the containers or the count container changed (the parent re-reads the item). */
    (e: 'changed'): void;
}>();

const { t, locale } = useI18n();

const holdUnits = computed(() => kindUnits(props.storedUnit));
const counted = computed(() => kindOfUnit(props.storedUnit) === 'counted');

function unitLabel(unit: string): string {
    return unit === 'piece' || unit === 'pack' || unit === 'box' ? t(`inventory.units.${unit}`) : unitOptionLabel(unit, locale.value);
}

let seq = 0;
function blankDraft(): ContainerDraft {
    seq += 1;
    return { key: Date.now() + seq, name: '', name_ar: '', mode: 'amount', amount: '', unit: holdUnits.value[0]?.value ?? '', contains_index: null, contains_quantity: '', count_container: false, barcodes: [] };
}

// ---------------------------------------------------------------- create

function patchDraft(index: number, patch: Partial<ContainerDraft>): void {
    emit('update:drafts', props.drafts.map((d, i) => (i === index ? { ...d, ...patch } : (patch.count_container ? { ...d, count_container: false } : d))));
}

function addDraft(): void {
    emit('update:drafts', [...props.drafts, blankDraft()]);
}

function removeDraft(index: number): void {
    // A later row that held this one goes back to holding an amount.
    emit('update:drafts', props.drafts
        .filter((_, i) => i !== index)
        .map((d) => (d.contains_index === null ? d : d.contains_index === index ? { ...d, mode: 'amount', contains_index: null } : d.contains_index > index ? { ...d, contains_index: d.contains_index - 1 } : d)));
}

/** A draft's size in the stored unit, or null while incomplete. */
function draftFactor(index: number, depth = 0): number | null {
    const d = props.drafts[index];
    if (!d || depth > 3) return null;
    if (d.mode === 'nested') {
        if (d.contains_index === null) return null;
        const child = draftFactor(d.contains_index, depth + 1);
        const q = parseFloat(String(d.contains_quantity));
        return child !== null && Number.isFinite(q) && q >= 2 ? child * q : null;
    }
    return toStoredAmount(d.amount, d.unit, props.storedUnit);
}

function draftLabel(index: number): string {
    const d = props.drafts[index];
    const factor = draftFactor(index);
    if (!d) return '';
    if (factor === null) return d.name || `#${index + 1}`;
    const f = friendlyAmount(factor, props.storedUnit);
    return `${d.name || `#${index + 1}`} ${f.amount} ${f.unit}`;
}

function draftWarning(index: number): string | null {
    const factor = draftFactor(index);
    const w = factor === null ? null : containerSizeWarning(factor, props.storedUnit);
    return w ? t(w.key, w.params) : null;
}

// ------------------------------------------------------------------ edit

const rows = ref<IngredientAltUnit[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const busyUuid = ref<string | null>(null);
const rowErrors = reactive<Record<string, string | null>>({});
const edits = reactive<Record<string, { name: string; name_ar: string; mode: 'amount' | 'nested'; amount: string; unit: string; contains_unit_uuid: string; contains_quantity: string }>>({});
const fresh = reactive<{ name: string; name_ar: string; mode: 'amount' | 'nested'; amount: string; unit: string; contains_unit_uuid: string; contains_quantity: string }>({
    name: '', name_ar: '', mode: 'amount', amount: '', unit: '', contains_unit_uuid: '', contains_quantity: '',
});

function syncEdits(): void {
    for (const k of Object.keys(edits)) delete edits[k];
    for (const r of rows.value) {
        const holds = holdsEntry(r.factor, props.storedUnit);
        edits[r.uuid] = {
            name: r.name,
            name_ar: r.name_ar ?? '',
            mode: r.contains_unit_uuid ? 'nested' : 'amount',
            amount: holds.amount,
            unit: holds.unit,
            contains_unit_uuid: r.contains_unit_uuid ?? '',
            contains_quantity: r.contains_quantity ?? '',
        };
    }
}

async function load(): Promise<void> {
    if (props.mode !== 'edit' || !props.ingredientUuid) return;
    loading.value = true;
    error.value = null;
    try {
        rows.value = (await listIngredientUnits(props.ingredientUuid)).data;
        syncEdits();
    } catch (e) {
        error.value = e instanceof Error ? e.message : t('containers.errors.load_failed');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.mode, props.ingredientUuid], () => {
    fresh.unit = holdUnits.value[0]?.value ?? '';
    void load();
}, { immediate: true });

function message(e: unknown, fallback: string): string {
    if (e instanceof ApiError) return e.firstValidationMessage() ?? e.message ?? t(fallback);
    return e instanceof Error ? e.message : t(fallback);
}

function sizePayload(source: { mode: 'amount' | 'nested'; amount: string; unit: string; contains_unit_uuid: string; contains_quantity: string }): Record<string, string> {
    return source.mode === 'nested'
        ? { contains_unit_uuid: source.contains_unit_uuid, contains_quantity: String(source.contains_quantity).trim() }
        : { amount: String(source.amount).trim(), unit: source.unit };
}

async function addRow(): Promise<void> {
    if (!props.ingredientUuid) return;
    busyUuid.value = '';
    rowErrors[''] = null;
    try {
        await createIngredientUnit(props.ingredientUuid, {
            name: fresh.name.trim(),
            name_ar: fresh.name_ar.trim() || null,
            ...sizePayload(fresh),
        });
        Object.assign(fresh, { name: '', name_ar: '', mode: 'amount', amount: '', contains_unit_uuid: '', contains_quantity: '' });
        await load();
        emit('changed');
    } catch (e) {
        rowErrors[''] = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

async function saveRow(row: IngredientAltUnit): Promise<void> {
    if (!props.ingredientUuid) return;
    const edit = edits[row.uuid];
    if (!edit) return;
    busyUuid.value = row.uuid;
    rowErrors[row.uuid] = null;
    try {
        await updateIngredientUnit(props.ingredientUuid, row.uuid, {
            name: edit.name.trim(),
            name_ar: edit.name_ar.trim() || null,
            ...(row.size_locked ? {} : sizePayload(edit)),
        });
        await load();
        emit('changed');
    } catch (e) {
        rowErrors[row.uuid] = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

async function removeRow(row: IngredientAltUnit): Promise<void> {
    if (!props.ingredientUuid) return;
    const isCount = row.is_count_container === true;
    if (!window.confirm(isCount ? `${t('containers.delete_confirm')}\n${t('containers.count_remove_warning', { unit: tillUnit.value })}` : t('containers.delete_confirm'))) return;
    busyUuid.value = row.uuid;
    rowErrors[row.uuid] = null;
    try {
        await deleteIngredientUnit(props.ingredientUuid, row.uuid);
        await load();
        emit('changed');
    } catch (e) {
        rowErrors[row.uuid] = message(e, 'containers.errors.delete_failed');
    } finally {
        busyUuid.value = null;
    }
}

/** A3 — what tills count in once the count container goes: kg / l (or pieces). */
const tillUnit = computed(() => {
    const kind = kindOfUnit(props.storedUnit);
    return kind === 'weighed' ? 'kg' : kind === 'liquid' ? 'l' : t(`inventory.units.${props.storedUnit}`);
});

/**
 * Fix order B-1 (M3) — what a marker change tells the merchant before it
 * saves: removing it, or MOVING it to another container (tills switch after
 * their next settings refresh; counts not yet sent may be converted at the
 * new size). Null = nothing to ask (the first marker).
 */
function markerWarning(uuid: string | null): string | null {
    if (uuid === null) return t('containers.count_remove_warning', { unit: tillUnit.value });
    const current = props.countContainerUuid ?? null;
    if (current === null || current === uuid) return null;
    const target = rows.value.find((r) => r.uuid === uuid);
    return t('containers.count_move_warning', { container: target ? rowLabel(target) : '' });
}

async function setCountContainer(uuid: string | null): Promise<void> {
    if (!props.ingredientUuid) return;
    const warning = markerWarning(uuid);
    if (warning !== null && !window.confirm(warning)) {
        await load();
        return;
    }
    busyUuid.value = uuid ?? 'none';
    error.value = null;
    try {
        await updateIngredient(props.ingredientUuid, { count_container_uuid: uuid });
        await load();
        emit('changed');
    } catch (e) {
        error.value = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

/**
 * Fix order B-1 (M4) — whole or part containers is set here, with the count
 * container (the server refuses it on its own).
 */
async function setFractional(value: boolean): Promise<void> {
    if (!props.ingredientUuid) return;
    busyUuid.value = 'fractional';
    error.value = null;
    try {
        await updateIngredient(props.ingredientUuid, { count_container_uuid: props.countContainerUuid ?? null, allow_fractional_pieces: value });
        emit('changed');
    } catch (e) {
        error.value = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

// Fix order B-1 (M2) — a code that is on another live item: offer to move it here.
const conflicts = reactive<Record<string, { code: string; message: string; holderUuid: string } | null>>({});

async function addBarcode(row: IngredientAltUnit, code: string): Promise<void> {
    if (!props.ingredientUuid) return;
    busyUuid.value = row.uuid;
    rowErrors[row.uuid] = null;
    conflicts[row.uuid] = null;
    try {
        await createBarcode(code, { item_type: 'ingredient', item_uuid: props.ingredientUuid, container_uuid: row.uuid });
        await load();
    } catch (e) {
        const conflict = barcodeConflictOf(e);
        if (conflict) conflicts[row.uuid] = { code, ...conflict };
        else rowErrors[row.uuid] = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

async function moveBarcode(row: IngredientAltUnit): Promise<void> {
    const conflict = conflicts[row.uuid];
    if (!props.ingredientUuid || !conflict) return;
    busyUuid.value = row.uuid;
    try {
        await moveBarcodeHere(conflict.holderUuid, conflict.code, { item_type: 'ingredient', item_uuid: props.ingredientUuid, container_uuid: row.uuid });
        conflicts[row.uuid] = null;
        await load();
    } catch (e) {
        rowErrors[row.uuid] = message(e, 'containers.errors.save_failed');
    } finally {
        busyUuid.value = null;
    }
}

async function removeBarcode(row: IngredientAltUnit, index: number): Promise<void> {
    const code = row.barcodes?.[index];
    if (!code) return;
    busyUuid.value = row.uuid;
    try {
        await deleteBarcode(code.uuid);
        await load();
    } catch (e) {
        rowErrors[row.uuid] = message(e, 'containers.errors.delete_failed');
    } finally {
        busyUuid.value = null;
    }
}

function rowLabel(row: IngredientAltUnit): string {
    return (locale.value === 'ar' ? row.display_name_ar : undefined) ?? row.display_name ?? row.name;
}

defineExpose({ reload: load });
</script>

<template>
    <fieldset class="rounded-lg border border-slate-200 p-3" data-test="containers-editor">
        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('containers.title') }}</legend>
        <p class="mb-2 text-xs text-slate-500">{{ mode === 'create' ? t('containers.hint') : t('containers.edit_hint') }}</p>

        <!-- ============ create: drafts sent with the new item ============ -->
        <template v-if="mode === 'create'">
            <ul v-if="drafts.length > 0" class="mb-2 space-y-2">
                <li v-for="(d, i) in drafts" :key="d.key" class="space-y-2 rounded border border-slate-200 bg-slate-50/50 p-2" data-test="container-draft">
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="block min-w-[8rem] flex-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name') }} *</span>
                            <input :value="d.name" type="text" maxlength="32" :placeholder="t('containers.name_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm" @input="patchDraft(i, { name: ($event.target as HTMLInputElement).value })">
                        </label>
                        <label class="block w-32">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name_ar') }}</span>
                            <input :value="d.name_ar" type="text" dir="rtl" maxlength="32" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm" @input="patchDraft(i, { name_ar: ($event.target as HTMLInputElement).value })">
                        </label>
                        <button type="button" class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50" :title="t('containers.remove')" @click="removeDraft(i)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-medium text-slate-600">{{ t('containers.holds') }}</span>
                        <select :value="d.mode" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm" data-test="container-mode" @change="patchDraft(i, { mode: ($event.target as HTMLSelectElement).value as 'amount' | 'nested' })">
                            <option value="amount">{{ t('containers.mode_amount') }}</option>
                            <option value="nested" :disabled="i === 0">{{ t('containers.mode_nested') }}</option>
                        </select>
                        <template v-if="d.mode === 'amount'">
                            <input :value="d.amount" type="number" step="0.0001" min="0" inputmode="decimal" placeholder="1.5" class="w-24 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums" @input="patchDraft(i, { amount: ($event.target as HTMLInputElement).value })">
                            <select :value="d.unit" class="w-28 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm" @change="patchDraft(i, { unit: ($event.target as HTMLSelectElement).value })">
                                <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ unitLabel(u.value) }}</option>
                            </select>
                        </template>
                        <template v-else>
                            <input :value="d.contains_quantity" type="number" step="1" min="2" placeholder="12" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums" @input="patchDraft(i, { contains_quantity: ($event.target as HTMLInputElement).value })">
                            <span>×</span>
                            <select :value="d.contains_index ?? ''" class="min-w-[9rem] rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm" @change="patchDraft(i, { contains_index: ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value) })">
                                <option value="" disabled>{{ t('containers.pick_content') }}</option>
                                <option v-for="j in i" :key="j - 1" :value="j - 1">{{ draftLabel(j - 1) }}</option>
                            </select>
                        </template>
                        <label class="ms-auto inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700" data-test="count-marker">
                            <input type="radio" name="count-container-draft" :checked="d.count_container" class="size-4 border-slate-300 text-teal-600" @change="patchDraft(i, { count_container: true })">
                            {{ t('containers.count_marker') }}
                        </label>
                    </div>
                    <p v-if="draftWarning(i)" class="text-[11px] font-semibold text-amber-700">{{ draftWarning(i) }}</p>
                    <BarcodeChips
                        :barcodes="d.barcodes.map((code) => ({ barcode: code }))"
                        editable
                        @add="patchDraft(i, { barcodes: [...d.barcodes, $event] })"
                        @remove="patchDraft(i, { barcodes: d.barcodes.filter((_, j) => j !== $event) })"
                    />
                </li>
            </ul>
            <p v-if="drafts.some((d) => d.count_container)" class="mb-2 text-[11px] text-slate-500">{{ t('containers.count_marker_hint') }}</p>
            <button type="button" :disabled="storedUnit === ''" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60" data-test="add-container" @click="addDraft">
                <Plus class="size-3.5" /> {{ t('containers.add') }}
            </button>
            <p v-if="storedUnit === ''" class="mt-1 text-[11px] text-slate-500">{{ t('item_kind.pack_sizes.choose_kind_first') }}</p>
        </template>

        <!-- ============ edit: each row saves on its own ============ -->
        <template v-else>
            <div v-if="error" class="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ error }}</div>
            <div v-if="loading" class="text-xs text-slate-500">{{ t('common.loading') }}</div>
            <template v-else>
                <div v-if="rows.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">{{ t('containers.empty') }}</div>
                <ul v-else class="space-y-2" data-test="containers-edit">
                    <li v-for="row in rows" :key="row.uuid" class="space-y-2 rounded border border-slate-200 bg-slate-50/50 p-2">
                        <div class="flex flex-wrap items-end gap-2">
                            <span class="basis-full text-xs font-semibold text-slate-800" data-test="container-display-name">{{ rowLabel(row) }}</span>
                            <template v-if="edits[row.uuid]">
                                <label class="block min-w-[8rem] flex-1">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name') }}</span>
                                    <input v-model="edits[row.uuid].name" type="text" maxlength="32" :disabled="!canManage" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm disabled:bg-slate-50">
                                </label>
                                <label class="block w-32">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name_ar') }}</span>
                                    <input v-model="edits[row.uuid].name_ar" type="text" dir="rtl" maxlength="32" :disabled="!canManage" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm disabled:bg-slate-50">
                                </label>
                            </template>
                        </div>
                        <div v-if="edits[row.uuid]" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium text-slate-600">{{ t('containers.holds') }}</span>
                            <template v-if="row.size_locked">
                                <span class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-600" data-test="container-size-locked" :title="t('containers.size_locked')">{{ t('containers.size_locked_short') }}</span>
                            </template>
                            <template v-else-if="edits[row.uuid].mode === 'amount'">
                                <input v-model="edits[row.uuid].amount" type="number" step="0.0001" min="0" :disabled="!canManage" class="w-24 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                                <select v-model="edits[row.uuid].unit" :disabled="!canManage" class="w-28 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                    <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ unitLabel(u.value) }}</option>
                                </select>
                            </template>
                            <template v-else>
                                <input v-model="edits[row.uuid].contains_quantity" type="number" step="1" min="2" :disabled="!canManage" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                                <span>×</span>
                                <select v-model="edits[row.uuid].contains_unit_uuid" :disabled="!canManage" class="min-w-[9rem] rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                    <option v-for="c in rows.filter((x) => x.uuid !== row.uuid)" :key="c.uuid" :value="c.uuid">{{ rowLabel(c) }}</option>
                                </select>
                            </template>
                            <label class="ms-auto inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700" data-test="count-marker">
                                <input type="radio" name="count-container-edit" :checked="row.is_count_container === true" :disabled="!canManage || busyUuid !== null" class="size-4 border-slate-300 text-teal-600" @change="setCountContainer(row.uuid)">
                                {{ t('containers.count_marker') }}
                            </label>
                        </div>
                        <p v-if="row.size_locked" class="text-[11px] text-slate-500">{{ t('containers.size_locked') }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <Barcode class="size-3.5 text-slate-400" />
                            <BarcodeChips :barcodes="row.barcodes ?? []" :editable="canManage" :busy="busyUuid === row.uuid" :conflict="conflicts[row.uuid] ?? null" @add="addBarcode(row, $event)" @remove="removeBarcode(row, $event)" @move="moveBarcode(row)" />
                        </div>
                        <div v-if="canManage" class="flex items-center gap-1">
                            <button type="button" :disabled="busyUuid === row.uuid" class="inline-flex h-8 items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-2.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:opacity-60" @click="saveRow(row)">
                                <Check class="size-3.5" /> {{ t('containers.save') }}
                            </button>
                            <button type="button" :disabled="busyUuid === row.uuid" class="grid size-8 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50 disabled:opacity-60" :title="t('containers.remove')" @click="removeRow(row)">
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                        <p v-if="rowErrors[row.uuid]" class="text-[11px] text-rose-600">{{ rowErrors[row.uuid] }}</p>
                    </li>
                </ul>
                <div v-if="canManage && countContainerUuid" class="mt-2">
                    <button type="button" :disabled="busyUuid !== null" class="text-[11px] font-semibold text-slate-500 underline hover:text-slate-700" data-test="count-marker-none" @click="setCountContainer(null)">{{ t('containers.count_marker_none') }}</button>
                </div>
                <!-- Fix order B-1 (M4) — whole or part containers, saved with the count container. -->
                <label v-if="rows.length > 0" class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-700" data-test="containers-fractional">
                    <input type="checkbox" :checked="allowFractional" :disabled="!canManage || busyUuid !== null" class="size-4 rounded border-slate-300 text-teal-600" @change="setFractional(($event.target as HTMLInputElement).checked)">
                    {{ t('inventory.piece.allow_fractional') }}
                </label>

                <!-- Add a container -->
                <div v-if="canManage" class="mt-3 space-y-2 rounded border border-teal-100 bg-teal-50/40 p-2" data-test="container-new">
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="block min-w-[8rem] flex-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name') }} *</span>
                            <input v-model="fresh.name" type="text" maxlength="32" :placeholder="t('containers.name_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                        </label>
                        <label class="block w-32">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.name_ar') }}</span>
                            <input v-model="fresh.name_ar" type="text" dir="rtl" maxlength="32" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-medium text-slate-600">{{ t('containers.holds') }}</span>
                        <select v-model="fresh.mode" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                            <option value="amount">{{ t('containers.mode_amount') }}</option>
                            <option value="nested" :disabled="rows.length === 0">{{ t('containers.mode_nested') }}</option>
                        </select>
                        <template v-if="fresh.mode === 'amount'">
                            <input v-model="fresh.amount" type="number" step="0.0001" min="0" placeholder="1.5" class="w-24 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                            <select v-model="fresh.unit" class="w-28 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ unitLabel(u.value) }}</option>
                            </select>
                        </template>
                        <template v-else>
                            <input v-model="fresh.contains_quantity" type="number" step="1" min="2" placeholder="12" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                            <span>×</span>
                            <select v-model="fresh.contains_unit_uuid" class="min-w-[9rem] rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                <option value="" disabled>{{ t('containers.pick_content') }}</option>
                                <option v-for="c in rows" :key="c.uuid" :value="c.uuid">{{ rowLabel(c) }}</option>
                            </select>
                        </template>
                        <button type="button" :disabled="busyUuid !== null || !fresh.name.trim()" class="ms-auto inline-flex h-8 items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:opacity-60" @click="addRow">
                            <Plus class="size-3.5" /> {{ t('containers.add') }}
                        </button>
                    </div>
                    <p v-if="fresh.mode === 'amount' && containerSizeWarning(toStoredAmount(fresh.amount, fresh.unit, storedUnit) ?? 0, storedUnit)" class="text-[11px] font-semibold text-amber-700">
                        {{ t(containerSizeWarning(toStoredAmount(fresh.amount, fresh.unit, storedUnit) ?? 0, storedUnit)!.key, containerSizeWarning(toStoredAmount(fresh.amount, fresh.unit, storedUnit) ?? 0, storedUnit)!.params) }}
                    </p>
                    <p v-if="rowErrors['']" class="text-[11px] text-rose-600">{{ rowErrors[''] }}</p>
                </div>
                <p v-if="counted" class="mt-1 text-[11px] text-slate-400">{{ t('containers.counted_hint') }}</p>
            </template>
        </template>
    </fieldset>
</template>

<script setup lang="ts">
/**
 * Floor planner — a 3-column grid of SHAPED table cards mirroring the Main POS
 * dine-in (oval=ellipse, round=pill, square/counter/rectangle). Drag a card to
 * REORDER it; the new order (display_order) saves and the POS shows tables in
 * that exact order. Up to ~9 tables read as a 3x3. Table content (name / shape
 * / seats) is edited in the parent's table modals — this view reorders.
 *
 * Persistence: only the tables whose order changed are PATCHed (display_order),
 * via the existing per-table endpoint. No x/y positions — the POS lays tables
 * out as a grid, so order is all that matters.
 */

import { GripVertical, Save, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import { updateTable, type Floor, type TableShape } from '@/lib/api/floorPlan';

const props = defineProps<{
    floor: Floor;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved'): void;
}>();

const { t } = useI18n();

interface Card {
    uuid: string;
    label: string;
    seats: number;
    shape: TableShape;
    status: string;
}

const working = reactive<Card[]>([]);
const baseline = ref('');
const saving = ref(false);
const saveError = ref<string | null>(null);
const dragIndex = ref<number | null>(null);

function load(): void {
    working.splice(0);
    const sorted = [...props.floor.tables].sort((a, b) => a.display_order - b.display_order);
    for (const tb of sorted) {
        working.push({
            uuid: tb.uuid,
            label: tb.label,
            seats: tb.seats,
            shape: (tb.shape ?? 'square') as TableShape,
            status: tb.status ?? 'active',
        });
    }
    baseline.value = working.map((c) => c.uuid).join('|');
}

onMounted(load);

const isDirty = computed(() => working.map((c) => c.uuid).join('|') !== baseline.value);
const canSave = computed(() => props.canManage && isDirty.value && !saving.value);

function onDragStart(index: number): void {
    if (props.canManage) {
        dragIndex.value = index;
    }
}

function onDrop(targetIndex: number): void {
    const from = dragIndex.value;
    dragIndex.value = null;
    if (from === null || from === targetIndex) {
        return;
    }
    const moved = working.splice(from, 1)[0];
    if (moved === undefined) {
        return;
    }
    working.splice(targetIndex, 0, moved);
}

function shapeRadius(shape: TableShape): string {
    switch (shape) {
        case 'oval':
            return '50%';
        case 'round':
            return '9999px';
        case 'square':
            return '16px';
        case 'counter':
            return '8px';
        default: // rectangle
            return '32px';
    }
}

function statusClass(status: string): string {
    if (status === 'inactive') {
        return 'border-slate-300 bg-slate-50 text-slate-500';
    }
    return 'border-teal-400 bg-teal-50 text-teal-900';
}

async function onSave(): Promise<void> {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    saveError.value = null;
    try {
        // Persist only the tables whose order actually changed.
        await Promise.all(
            working.map((card, index) => {
                const original = props.floor.tables.find((tb) => tb.uuid === card.uuid);
                if (original && original.display_order === index) {
                    return Promise.resolve(null);
                }
                return updateTable(card.uuid, { display_order: index });
            }),
        );
        baseline.value = working.map((c) => c.uuid).join('|');
        emit('saved');
    } catch (err) {
        saveError.value = err instanceof ApiError
            ? err.message
            : err instanceof Error
                ? err.message
                : 'Save failed';
    } finally {
        saving.value = false;
    }
}

function onCancel(): void {
    if (isDirty.value && !window.confirm(t('floor_plan.planner.confirm_discard'))) {
        return;
    }
    emit('close');
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('floor_plan.planner.title', { name: floor.name }) }}
                </h2>
                <span class="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-teal-700">
                    {{ t('floor_plan.planner.table_count', { count: working.length }) }}
                </span>
                <span v-if="isDirty" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800">
                    {{ t('floor_plan.planner.unsaved') }}
                </span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    @click="onCancel"
                >
                    <X class="size-3.5" />
                    {{ t('floor_plan.planner.close') }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    :disabled="!canSave"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg disabled:cursor-not-allowed disabled:from-slate-300 disabled:to-slate-400 disabled:shadow-none disabled:hover:translate-y-0"
                    @click="onSave"
                >
                    <Save class="size-3.5" />
                    {{ saving ? t('floor_plan.planner.saving') : t('floor_plan.planner.save') }}
                </button>
            </div>
        </header>

        <div v-if="saveError" class="border-b border-rose-200 bg-rose-50 px-5 py-2 text-sm font-medium text-rose-700">
            {{ saveError }}
        </div>

        <div class="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-500">
            {{ canManage ? t('floor_plan.planner.help_can_manage') : t('floor_plan.planner.help_view_only') }}
        </div>

        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="(card, index) in working"
                :key="card.uuid"
                class="relative flex aspect-[2/1] flex-col items-center justify-center border-2 px-3 text-center shadow-sm transition"
                :class="[
                    statusClass(card.status),
                    canManage ? 'cursor-grab active:cursor-grabbing hover:shadow-md' : '',
                    dragIndex === index ? 'opacity-50 ring-2 ring-teal-400' : '',
                ]"
                :style="{ borderRadius: shapeRadius(card.shape) }"
                :draggable="canManage"
                @dragstart="onDragStart(index)"
                @dragover.prevent
                @drop="onDrop(index)"
            >
                <GripVertical v-if="canManage" class="absolute start-2 top-2 size-3.5 opacity-30" />
                <span class="text-2xl font-black leading-none">{{ card.label }}</span>
                <span class="mt-1.5 text-xs font-semibold opacity-80">
                    {{ t('floor_plan.planner.seats_n', { count: card.seats }) }}
                </span>
            </article>
        </div>
    </section>
</template>

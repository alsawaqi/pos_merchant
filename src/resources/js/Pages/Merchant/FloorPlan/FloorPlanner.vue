<script setup lang="ts">
/**
 * Phase 5.5 — visual drag-and-drop floor planner.
 *
 * Renders the floor's tables on a fixed 1200x800 canvas where
 * the merchant can grab + drag each one. Snap-to-grid is 20px
 * so the layout stays tidy without nano-pixel fights.
 *
 * Drag impl uses native pointer events (no dnd library). The
 * total surface area of "draggable nodes" is small (tens of
 * tables per floor) so the simpler implementation wins —
 * Sortable.js etc. are tuned for list reordering, not 2D.
 *
 * Tables whose stored position is NULL get auto-arranged in a
 * grid on mount — so a fresh planner session for a never-
 * planned floor immediately shows every table on screen
 * instead of all stacked at (0,0). Saving the layout
 * persists those auto-positions back to the DB.
 *
 * Dirty tracking lets us:
 *   - enable / disable the Save button without N-equals checks
 *     in the template
 *   - prompt before closing if the user wandered off
 *
 * ESC discards in-progress drag (but not committed local
 * changes — Cancel button does that). Save button POSTs the
 * full table list to /api/floors/{uuid}/layout; the backend
 * writes one audit row per save (not N per table).
 */

import { Move, Save, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import {
    saveFloorLayout,
    type Floor,
    type LayoutTableItem,
    type MerchantTable,
    type TableShape,
} from '@/lib/api/floorPlan';

const props = defineProps<{
    floor: Floor;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved'): void;
}>();

const { t } = useI18n();

// Canvas dimensions. Fixed; if a merchant needs a bigger
// floor we can promote these to per-floor settings in a
// future iteration. 1200x800 fits a typical restaurant +
// keeps the math + math-tests trivial.
const CANVAS_W = 1200;
const CANVAS_H = 800;
const SNAP = 20;

/**
 * Visual default size per shape. Stored width/height
 * override these — but most tables on a fresh planner have
 * NULL sizes and rely on the defaults.
 */
const SHAPE_DEFAULTS: Record<TableShape, { w: number; h: number }> = {
    round: { w: 80, h: 80 },
    square: { w: 80, h: 80 },
    rectangle: { w: 120, h: 60 },
    oval: { w: 100, h: 70 },
    counter: { w: 160, h: 40 },
};

interface WorkingPos {
    uuid: string;
    label: string;
    shape: TableShape;
    seats: number;
    status: string;
    x: number;
    y: number;
    w: number;
    h: number;
}

// Local working state — a flat array (not the parent's
// floor.tables) so dragging mutates only us. Parent's data
// only updates on successful save.
const positions = reactive<WorkingPos[]>([]);

// Snapshot the post-mount state so we can compute "dirty"
// for Save-button enabling + close-confirmation.
const baseline = ref<string>('');

const dragging = ref<{
    uuid: string;
    offsetX: number;
    offsetY: number;
    originalX: number;
    originalY: number;
} | null>(null);

const saving = ref(false);
const saveError = ref<string | null>(null);

onMounted(() => {
    initPositions();
    baseline.value = serialize(positions);
    window.addEventListener('keydown', onKeyDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
});

/**
 * Populate the local working state from the floor's tables.
 * Tables with NULL position get auto-arranged in a 6-col
 * grid starting at (40, 40), 140px steps. Subsequent
 * placements respect any already-set NULL slots.
 */
function initPositions(): void {
    positions.splice(0);
    let autoIdx = 0;
    const cols = 6;
    const stepX = 140;
    const stepY = 140;

    for (const t of props.floor.tables) {
        const shape = (t.shape ?? 'square') as TableShape;
        const def = SHAPE_DEFAULTS[shape] ?? SHAPE_DEFAULTS.square;
        const hasPos = t.position_x !== null && t.position_y !== null;
        let x: number;
        let y: number;
        if (hasPos) {
            x = t.position_x as number;
            y = t.position_y as number;
        } else {
            x = 40 + (autoIdx % cols) * stepX;
            y = 40 + Math.floor(autoIdx / cols) * stepY;
            autoIdx += 1;
        }
        positions.push({
            uuid: t.uuid,
            label: t.label,
            shape,
            seats: t.seats,
            status: t.status ?? 'active',
            x,
            y,
            w: t.width ?? def.w,
            h: t.height ?? def.h,
        });
    }
}

function serialize(p: WorkingPos[]): string {
    return p.map((row) => `${row.uuid}:${row.x},${row.y},${row.w},${row.h}`).join('|');
}

const isDirty = computed(() => serialize(positions) !== baseline.value);
const canSave = computed(() => props.canManage && isDirty.value && !saving.value);

function snap(value: number): number {
    return Math.round(value / SNAP) * SNAP;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

// --- pointer drag plumbing ----------------------------------

function onPointerDown(event: PointerEvent, item: WorkingPos): void {
    if (!props.canManage) return;
    const target = event.currentTarget as HTMLElement;
    target.setPointerCapture(event.pointerId);
    const canvasRect = (target.offsetParent as HTMLElement).getBoundingClientRect();
    dragging.value = {
        uuid: item.uuid,
        offsetX: event.clientX - canvasRect.left - item.x,
        offsetY: event.clientY - canvasRect.top - item.y,
        originalX: item.x,
        originalY: item.y,
    };
}

function onPointerMove(event: PointerEvent, item: WorkingPos): void {
    const d = dragging.value;
    if (!d || d.uuid !== item.uuid) return;
    const target = event.currentTarget as HTMLElement;
    const canvasRect = (target.offsetParent as HTMLElement).getBoundingClientRect();
    let nx = event.clientX - canvasRect.left - d.offsetX;
    let ny = event.clientY - canvasRect.top - d.offsetY;
    nx = clamp(snap(nx), 0, CANVAS_W - item.w);
    ny = clamp(snap(ny), 0, CANVAS_H - item.h);
    item.x = nx;
    item.y = ny;
}

function onPointerUp(event: PointerEvent, item: WorkingPos): void {
    const d = dragging.value;
    if (!d || d.uuid !== item.uuid) return;
    const target = event.currentTarget as HTMLElement;
    target.releasePointerCapture(event.pointerId);
    dragging.value = null;
}

function onKeyDown(event: KeyboardEvent): void {
    // ESC cancels an in-flight drag (snap back to where we
    // grabbed). Doesn't touch committed positions — Cancel
    // button does that.
    if (event.key === 'Escape' && dragging.value) {
        const d = dragging.value;
        const item = positions.find((p) => p.uuid === d.uuid);
        if (item) {
            item.x = d.originalX;
            item.y = d.originalY;
        }
        dragging.value = null;
    }
}

// --- shape rendering helpers --------------------------------

function shapeStyle(item: WorkingPos): Record<string, string> {
    const isRound = item.shape === 'round' || item.shape === 'oval';
    return {
        left: `${item.x}px`,
        top: `${item.y}px`,
        width: `${item.w}px`,
        height: `${item.h}px`,
        borderRadius: isRound ? '50%' : item.shape === 'counter' ? '4px' : '10px',
    };
}

function statusColor(status: string): string {
    if (status === 'inactive') return 'bg-slate-200 border-slate-300 text-slate-500';
    return 'bg-teal-100 border-teal-400 text-teal-900';
}

// --- save / cancel ------------------------------------------

async function onSave(): Promise<void> {
    if (!canSave.value) return;
    saving.value = true;
    saveError.value = null;
    try {
        const payload: LayoutTableItem[] = positions.map((p) => ({
            uuid: p.uuid,
            position_x: p.x,
            position_y: p.y,
            width: p.w,
            height: p.h,
        }));
        await saveFloorLayout(props.floor.uuid, { tables: payload });
        // Re-baseline so the dirty flag clears even if the
        // parent re-fetch is slow.
        baseline.value = serialize(positions);
        emit('saved');
    } catch (err) {
        if (err instanceof ApiError) {
            saveError.value = err.message;
        } else {
            saveError.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        saving.value = false;
    }
}

function onCancel(): void {
    if (isDirty.value) {
        // Confirm-via-native is intentionally low-fidelity —
        // we don't need a full modal for "you have unsaved
        // changes, sure you want to throw them away?".
        if (!window.confirm(t('floor_plan.planner.confirm_discard'))) {
            return;
        }
    }
    emit('close');
}

// Reactive helper: typed return as MerchantTable[] for the
// parent's benefit (parent will refetch on 'saved').
defineExpose({
    /** Snapshot of current local positions — useful for tests. */
    snapshot(): MerchantTable[] {
        return positions.map((p) => ({
            id: 0,
            uuid: p.uuid,
            floor_id: props.floor.id,
            label: p.label,
            seats: p.seats,
            min_party: null,
            max_party: null,
            shape: p.shape,
            notes: null,
            qr_token: '',
            status: p.status as MerchantTable['status'],
            display_order: 0,
            position_x: p.x,
            position_y: p.y,
            width: p.w,
            height: p.h,
            created_at: null,
            updated_at: null,
        }));
    },
});
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <!-- Header: title + actions -->
        <header class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <Move class="size-4 text-teal-600" />
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('floor_plan.planner.title', { name: floor.name }) }}
                </h2>
                <span class="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-teal-700">
                    {{ t('floor_plan.planner.table_count', { count: positions.length }) }}
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

        <!-- Error banner -->
        <div v-if="saveError" class="border-b border-rose-200 bg-rose-50 px-5 py-2 text-sm font-medium text-rose-700">
            {{ saveError }}
        </div>

        <!-- Helper hints -->
        <div class="border-b border-slate-100 bg-slate-50 px-5 py-2 text-xs text-slate-500">
            {{ canManage ? t('floor_plan.planner.help_can_manage') : t('floor_plan.planner.help_view_only') }}
        </div>

        <!-- Canvas wrapper — scrollable when canvas exceeds viewport -->
        <div class="overflow-auto bg-slate-100 p-4">
            <div
                class="relative bg-white shadow-inner ring-1 ring-slate-200"
                :style="{
                    width: `${CANVAS_W}px`,
                    height: `${CANVAS_H}px`,
                    backgroundImage:
                        'linear-gradient(to right, rgba(15,23,42,0.04) 1px, transparent 1px), linear-gradient(to bottom, rgba(15,23,42,0.04) 1px, transparent 1px)',
                    backgroundSize: `${SNAP}px ${SNAP}px`,
                }"
            >
                <article
                    v-for="item in positions"
                    :key="item.uuid"
                    class="absolute flex flex-col items-center justify-center border-2 text-xs font-semibold shadow-md transition-shadow"
                    :class="[
                        statusColor(item.status),
                        canManage ? 'cursor-grab active:cursor-grabbing hover:shadow-lg' : 'cursor-default',
                        dragging?.uuid === item.uuid ? 'ring-2 ring-teal-400 ring-offset-2 z-10' : '',
                    ]"
                    :style="shapeStyle(item)"
                    @pointerdown="onPointerDown($event, item)"
                    @pointermove="onPointerMove($event, item)"
                    @pointerup="onPointerUp($event, item)"
                >
                    <span class="px-1 leading-tight">{{ item.label }}</span>
                    <span class="text-[10px] font-medium opacity-80">{{ t('floor_plan.planner.seats_n', { count: item.seats }) }}</span>
                </article>
            </div>
        </div>
    </section>
</template>

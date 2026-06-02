<script setup lang="ts">
/**
 * Floor preview — shows the floor's tables as a 3-column grid of SHAPED cards,
 * mirroring the Main POS dine-in screen (round / oval / square / counter /
 * rectangle), at the same grid sizing. This replaces the old wide 1200x800
 * drag canvas: the POS lays tables out as a card grid (not by x/y position), so
 * the planner now follows the same methodology. Table editing (name, shape,
 * seats) stays in the parent's table modals; this view is read-only.
 *
 * Shapes use CSS border-radius: 50% on the 2:1 card => a true ellipse for oval,
 * a pill for round, matching the POS _diningCardShape mapping.
 */

import { X } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { type Floor, type TableShape } from '@/lib/api/floorPlan';

const props = defineProps<{
    floor: Floor;
    // Kept for the parent's prop contract; the preview is read-only.
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const { t } = useI18n();

const tables = computed(() => props.floor.tables);

/** Card outline per shape — mirrors the POS (oval=ellipse, round=pill, ...). */
function shapeRadius(shape: TableShape | null): string {
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

function statusClass(status: string | null): string {
    if (status === 'inactive') {
        return 'border-slate-300 bg-slate-50 text-slate-500';
    }
    return 'border-teal-400 bg-teal-50 text-teal-900';
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold text-slate-950">
                    {{ t('floor_plan.planner.title', { name: floor.name }) }}
                </h2>
                <span class="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-teal-700">
                    {{ t('floor_plan.planner.table_count', { count: tables.length }) }}
                </span>
            </div>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                @click="emit('close')"
            >
                <X class="size-3.5" />
                {{ t('floor_plan.planner.close') }}
            </button>
        </header>

        <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="table in tables"
                :key="table.uuid"
                class="flex aspect-[2/1] flex-col items-center justify-center border-2 px-3 text-center shadow-sm"
                :class="statusClass(table.status)"
                :style="{ borderRadius: shapeRadius(table.shape) }"
            >
                <span class="text-2xl font-black leading-none">{{ table.label }}</span>
                <span class="mt-1.5 text-xs font-semibold opacity-80">
                    {{ t('floor_plan.planner.seats_n', { count: table.seats }) }}
                </span>
            </article>
        </div>
    </section>
</template>

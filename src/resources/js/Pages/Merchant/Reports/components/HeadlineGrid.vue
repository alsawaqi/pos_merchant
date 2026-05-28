<script setup lang="ts">
/**
 * HeadlineGrid — Phase 7b-6.
 *
 * Renders a grid of headline value tiles. Used by every report
 * that has a "headline" section (Sales, Discounts, Loss/Waste,
 * Restock, Round-Up).
 *
 * items: [{ label, value }, ...]
 */

defineProps<{ items: { label: string; value: string | number }[] }>();
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div
            v-for="item in items"
            :key="item.label"
            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
        >
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ item.label }}</div>
            <div class="mt-2 text-2xl font-bold text-slate-950">{{ item.value }}</div>
        </div>
    </div>
</template>

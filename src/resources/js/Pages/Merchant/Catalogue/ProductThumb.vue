<script setup lang="ts">
/**
 * LAUNCH-P4 B5 — a catalogue-list thumbnail: the photo, or the item's
 * initials when it has none (or the link is broken).
 */
import { ref, watch } from 'vue';
import { initialsOf } from '@/lib/initials';

const props = defineProps<{ url: string | null; name: string }>();
const broken = ref(false);
watch(() => props.url, () => { broken.value = false; });
</script>

<template>
    <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-100" data-test="product-thumb">
        <img v-if="url && !broken" :src="url" alt="" loading="lazy" class="size-full object-cover" @error="broken = true">
        <span v-else class="text-xs font-bold text-slate-500" aria-hidden="true">{{ initialsOf(name) }}</span>
    </span>
</template>

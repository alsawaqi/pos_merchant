<script setup lang="ts">
/**
 * LAUNCH review add-on (B2) — the breakdown under a live total: "2 × bottle
 * 1.5 l + 3 × bottle 500 ml · counted 4 Oct". It is what was on the shelf at
 * the last purchase, transfer or count (recipe use lowers only the total), so
 * it may add up to more than the total; it never computes stock.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { breakdownText } from '@/lib/containers';
import type { BreakdownEntry } from '@/lib/api/inventory';

const props = withDefaults(defineProps<{
    rows?: BreakdownEntry[] | null;
    countedAt?: string | null;
    totalCountAt?: string | null;
    tone?: 'slate' | 'teal';
}>(), { rows: () => [], countedAt: null, totalCountAt: null, tone: 'slate' });

const { t, locale } = useI18n();

const text = computed(() => breakdownText(props.rows ?? [], locale.value));

function day(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', { day: 'numeric', month: 'short' });
}
</script>

<template>
    <span v-if="text || countedAt || totalCountAt" class="block text-[11px] font-normal" :class="tone === 'teal' ? 'text-teal-800' : 'text-slate-500'" data-test="stock-breakdown">
        <bdi v-if="text" dir="ltr">{{ text }}</bdi>
        <span v-else>{{ t('containers.breakdown_none') }}</span>
        <span v-if="countedAt" class="text-slate-400"> · {{ t('containers.breakdown_counted', { date: day(countedAt) }) }}</span>
        <span v-else-if="totalCountAt" class="text-slate-400"> · {{ t('containers.breakdown_total_count', { date: day(totalCountAt) }) }}</span>
    </span>
</template>

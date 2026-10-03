<script setup lang="ts">
/**
 * LAUNCH item kind, G3 — "Show in: Auto / kg·l / g·ml" for the branch stock
 * and warehouse stock lists (remembered per browser by useAmountDisplay).
 * Counted items are not affected.
 */
import { useI18n } from 'vue-i18n';
import { useAmountDisplay } from '@/composables/useAmountDisplay';
import { AMOUNT_DISPLAYS } from '@/lib/itemKind';

const { t } = useI18n();
const { mode } = useAmountDisplay();
</script>

<template>
    <div class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500" data-test="amount-display-switch">
        <span>{{ t('item_kind.show_in') }}</span>
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm" role="group">
            <button
                v-for="m in AMOUNT_DISPLAYS"
                :key="m"
                type="button"
                class="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                :class="mode === m ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'"
                :aria-pressed="mode === m"
                @click="mode = m"
            >
                {{ t(`item_kind.show_modes.${m}`) }}
            </button>
        </div>
    </div>
</template>

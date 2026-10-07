<script setup lang="ts">
/**
 * LAUNCH costs & allergens add-on — allergens shown as small labelled chips
 * ("Contains" amber, "May contain" outlined), in the portal's language.
 */
import { useI18n } from 'vue-i18n';
import { normaliseAllergens } from '@/lib/allergens';

withDefaults(defineProps<{
    contains?: string[];
    mayContain?: string[];
    showNone?: boolean;
}>(), { contains: () => [], mayContain: () => [], showNone: true });

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-wrap items-center gap-1" data-test="allergen-chips">
        <span
            v-for="code in normaliseAllergens(contains)"
            :key="`c-${code}`"
            class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900"
            :data-test="`allergen-chip-${code}`"
        >{{ t(`allergens.codes.${code}`) }}</span>
        <span
            v-for="code in normaliseAllergens(mayContain)"
            :key="`m-${code}`"
            class="rounded-full border border-dashed border-amber-300 px-2 py-0.5 text-[11px] font-medium text-amber-800"
            :data-test="`allergen-may-${code}`"
        >{{ t('allergens.may_contain') }}: {{ t(`allergens.codes.${code}`) }}</span>
        <span v-if="showNone && contains.length === 0 && mayContain.length === 0" class="text-xs italic text-slate-400">{{ t('allergens.none') }}</span>
    </div>
</template>

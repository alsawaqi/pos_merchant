<script setup lang="ts">
/**
 * LAUNCH costs & allergens add-on — the 14 allergens as ticks (English or
 * Arabic names from the locales). `locked` codes show ticked and cannot be
 * unticked (worked out from the ingredients); `hidden` codes are left out
 * (a "may contain" list never repeats what the dish contains).
 */
import { useI18n } from 'vue-i18n';
import { ALLERGEN_CODES, toggleAllergen } from '@/lib/allergens';

const props = withDefaults(defineProps<{
    modelValue: string[];
    locked?: string[];
    hidden?: string[];
    disabled?: boolean;
    testId?: string;
}>(), { locked: () => [], hidden: () => [], disabled: false, testId: 'allergen-ticks' });

const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>();
const { t } = useI18n();

function flip(code: string): void {
    if (props.disabled) return;
    emit('update:modelValue', toggleAllergen(props.modelValue, code, props.locked));
}
</script>

<template>
    <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-4" :data-test="testId">
        <label
            v-for="code in ALLERGEN_CODES.filter((c) => !hidden.includes(c))"
            :key="code"
            class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm transition"
            :class="[
                locked.includes(code) ? 'cursor-not-allowed border-amber-200 bg-amber-50 text-amber-900'
                : modelValue.includes(code) ? 'cursor-pointer border-teal-500 bg-teal-50 text-teal-900'
                : 'cursor-pointer border-slate-200 text-slate-700 hover:bg-slate-50',
                disabled ? 'opacity-60' : '',
            ]"
            :data-test="`allergen-tick-${code}`"
            :title="locked.includes(code) ? t('allergens.locked') : undefined"
        >
            <input
                type="checkbox"
                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                :checked="locked.includes(code) || modelValue.includes(code)"
                :disabled="disabled || locked.includes(code)"
                @change="flip(code)"
            >
            <span>{{ t(`allergens.codes.${code}`) }}</span>
        </label>
    </div>
</template>

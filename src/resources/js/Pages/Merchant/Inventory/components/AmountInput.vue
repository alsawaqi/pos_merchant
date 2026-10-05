<script setup lang="ts">
/**
 * LAUNCH review add-on (E1) — an amount box with its unit picker and a live
 * translation underneath: 2.5 l → "= 2 l 500 ml", 1500 ml → "= 1.5 l",
 * 3 crates → "= 36 × bottle 1 l = 36 l". Counted items get only the
 * container translation. The value is passed through untouched (the server
 * converts and is the authority).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { translateAmount, type SafetyContainer } from '@/lib/amountSafety';

const props = withDefaults(defineProps<{
    modelValue: string | number | null | undefined;
    /** The unit the amount is typed in ('' = the stored unit, a kind unit, or a container token). */
    unit?: string;
    /** When set, a unit picker is shown next to the box. */
    options?: { value: string; label: string }[] | null;
    storedUnit: string;
    containers?: SafetyContainer[];
    placeholder?: string;
    step?: string;
    min?: string;
    required?: boolean;
    disabled?: boolean;
    inputClass?: string;
    selectClass?: string;
    dataTest?: string;
}>(), {
    unit: '',
    options: null,
    containers: () => [],
    placeholder: '',
    step: 'any',
    min: '0',
    required: false,
    disabled: false,
    inputClass: 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100',
    selectClass: 'shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100',
    dataTest: 'amount-input',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'update:unit', value: string): void;
}>();

const { t, locale } = useI18n();

const translation = computed<string>(() => {
    const parts = translateAmount({
        amount: props.modelValue ?? '',
        unit: props.unit ?? '',
        storedUnit: props.storedUnit,
        containers: props.containers,
        locale: locale.value,
    });
    return parts.length > 0 ? t('amount_safety.translation', { text: parts.join(' = ') }) : '';
});
</script>

<template>
    <div>
        <div class="flex gap-2">
            <input
                :value="modelValue ?? ''"
                type="number"
                :step="step"
                :min="min"
                :required="required"
                :disabled="disabled"
                :placeholder="placeholder"
                :class="inputClass"
                :data-test="dataTest"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            >
            <select
                v-if="options && options.length > 0"
                :value="unit"
                :disabled="disabled"
                :title="t('inventory.fields.unit')"
                :class="selectClass"
                :data-test="`${dataTest}-unit`"
                @change="emit('update:unit', ($event.target as HTMLSelectElement).value)"
            >
                <option v-for="o in options" :key="o.value || 'base'" :value="o.value">{{ o.label }}</option>
            </select>
        </div>
        <bdi v-if="translation" dir="ltr" class="mt-1 block text-[11px] font-medium text-teal-700" data-test="amount-translation">{{ translation }}</bdi>
    </div>
</template>

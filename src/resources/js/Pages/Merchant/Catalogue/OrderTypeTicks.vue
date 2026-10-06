<script setup lang="ts">
/**
 * LAUNCH packaging add-on — "Used for": four small toggles on a stock line
 * (Dine in / Quick / To go / Delivery), all on by default. At sale, only the
 * lines ticked for the order's type are taken from stock (QR table and staff
 * rounds are dine in, QR quick orders are quick). A line with no tick cannot
 * be saved. Read-only: the ticked types as text, nothing when all four.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ALL_ORDER_TYPES, hasType, noTicks, ORDER_TYPE_BUCKETS, ticked, toggleType, type OrderTypeBucket } from '@/lib/orderTypes';

const props = withDefaults(defineProps<{
    modelValue: number;
    disabled?: boolean;
    readonly?: boolean;
}>(), {
    disabled: false,
    readonly: false,
});

const emit = defineEmits<{ (e: 'update:modelValue', mask: number): void }>();

const { t } = useI18n();

const empty = computed(() => noTicks(props.modelValue));
const readonlyText = computed(() => props.modelValue === ALL_ORDER_TYPES
    ? ''
    : ticked(props.modelValue).map((b) => t(`order_types.short.${b}`)).join(' · '));

function flip(bucket: OrderTypeBucket): void {
    if (props.disabled || props.readonly) return;
    emit('update:modelValue', toggleType(props.modelValue, bucket));
}
</script>

<template>
    <span v-if="readonly" class="text-[11px] text-slate-500" data-test="order-type-ticks-readonly">
        <template v-if="readonlyText">({{ t('order_types.used_for') }}: {{ readonlyText }})</template>
    </span>
    <span v-else class="inline-flex flex-wrap items-center gap-1" role="group" :aria-label="t('order_types.used_for')" data-test="order-type-ticks">
        <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('order_types.used_for') }}</span>
        <button
            v-for="bucket in ORDER_TYPE_BUCKETS"
            :key="bucket"
            type="button"
            :disabled="disabled"
            :aria-pressed="hasType(modelValue, bucket)"
            :data-test="`order-type-${bucket}`"
            class="rounded-full border px-1.5 py-0.5 text-[10px] font-semibold leading-none transition disabled:cursor-not-allowed disabled:opacity-50"
            :class="hasType(modelValue, bucket) ? 'border-teal-600 bg-teal-600 text-white' : 'border-slate-300 bg-white text-slate-500 hover:bg-slate-50'"
            @click="flip(bucket)"
        >{{ t(`order_types.short.${bucket}`) }}</button>
        <span v-if="empty" class="text-[10px] font-semibold text-rose-700" data-test="order-type-none">{{ t('order_types.pick_one') }}</span>
    </span>
</template>

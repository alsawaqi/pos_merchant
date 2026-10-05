<script setup lang="ts">
/**
 * LAUNCH review add-on (D12) — the "Can be removed" tick on one recipe line,
 * with an optional customer label ("Ketchup" instead of "Ketchup (Heinz 5 kg)").
 * The wizard saves the ticks to the product's own Remove list; the customer
 * taps "NO Ketchup", the kitchen ticket prints it, and for a made-to-order
 * product the ingredient is not taken from stock.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { removeOptionName, removeOptionNameAr, type RemovableDraft } from '@/lib/menuExtras';

const props = defineProps<{
    modelValue: RemovableDraft | undefined;
    ingredientName: string;
    ingredientNameAr: string | null;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: RemovableDraft] }>();
const { t } = useI18n();

const current = computed<RemovableDraft>(() => props.modelValue ?? { ticked: false, label: '', label_ar: '' });

function patch(change: Partial<RemovableDraft>): void {
    emit('update:modelValue', { ...current.value, ...change });
}

function onTick(event: Event): void {
    patch({ ticked: (event.target as HTMLInputElement).checked });
}

function onLabel(event: Event): void {
    patch({ label: (event.target as HTMLInputElement).value });
}

function onLabelAr(event: Event): void {
    patch({ label_ar: (event.target as HTMLInputElement).value });
}
</script>

<template>
    <div class="basis-full" data-test="removable-tick">
        <label class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-700">
            <input type="checkbox" :checked="current.ticked" :disabled="disabled" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200" data-test="removable-checkbox" @change="onTick">
            {{ t('menu_extras.removable.tick') }}
        </label>
        <div v-if="current.ticked" class="mt-1 grid gap-2 sm:grid-cols-2">
            <label class="block">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('menu_extras.removable.label') }}</span>
                <input :value="current.label" type="text" maxlength="60" :placeholder="ingredientName" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="removable-label" @input="onLabel">
            </label>
            <label class="block">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('menu_extras.removable.label_ar') }}</span>
                <input :value="current.label_ar" type="text" dir="rtl" maxlength="60" :placeholder="ingredientNameAr ?? ''" :disabled="disabled" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" data-test="removable-label-ar" @input="onLabelAr">
            </label>
            <p class="text-[11px] text-slate-500 sm:col-span-2" data-test="removable-preview">
                {{ t('menu_extras.removable.preview') }}
                <bdi class="font-semibold text-slate-700">{{ removeOptionName(current.label, ingredientName) }}</bdi>
                ·
                <bdi dir="rtl" class="font-semibold text-slate-700">{{ removeOptionNameAr(current.label_ar, ingredientNameAr, current.label.trim() || ingredientName) }}</bdi>
            </p>
        </div>
    </div>
</template>

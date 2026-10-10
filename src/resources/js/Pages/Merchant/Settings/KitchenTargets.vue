<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Area, Destination, Targets } from '@/lib/api/kitchen';
const props = defineProps<{ modelValue: Targets; areas: Area[]; destinations: Destination[]; disabled?: boolean; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [Targets] }>();
const { locale } = useI18n();
function toggle(key: 'areas' | 'destinations', id: string, checked: boolean) {
    emit('update:modelValue', { ...props.modelValue, [key]: checked ? [...props.modelValue[key], id] : props.modelValue[key].filter(v => v !== id) });
}
</script>
<template>
    <fieldset :disabled="disabled" class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
        <legend class="px-1 text-sm font-medium">{{ label }}</legend>
        <div><p class="mb-2 text-xs font-medium text-slate-500">{{ locale === 'ar' ? 'مناطق التحضير المطلوبة' : 'Required preparation areas' }}</p>
            <label v-for="a in areas" :key="a.id" class="me-4 mb-2 inline-flex items-center gap-2 text-sm"><input type="checkbox" :checked="modelValue.areas.includes(a.id)" @change="toggle('areas', a.id, ($event.target as HTMLInputElement).checked)">{{ a.name }}</label>
            <span v-if="!areas.length" class="text-xs text-slate-500">{{ locale === 'ar' ? 'أضف منطقة تحضير أولاً.' : 'Add a preparation area first.' }}</span>
        </div>
        <div><p class="mb-2 text-xs font-medium text-slate-500">{{ locale === 'ar' ? 'نسخ إلى الشاشات والطابعات' : 'Copies to screens and printers' }}</p>
            <label v-for="d in destinations" :key="d.id" class="me-4 mb-2 inline-flex items-center gap-2 text-sm"><input type="checkbox" :checked="modelValue.destinations.includes(d.id)" @change="toggle('destinations', d.id, ($event.target as HTMLInputElement).checked)">{{ d.name }}</label>
            <span v-if="!destinations.length" class="text-xs text-slate-500">{{ locale === 'ar' ? 'أضف وجهة أولاً.' : 'Add a destination first.' }}</span>
        </div>
    </fieldset>
</template>

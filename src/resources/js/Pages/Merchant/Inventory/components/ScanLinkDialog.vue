<script setup lang="ts">
/**
 * LAUNCH review add-on (F2) — "Which item is this barcode?": an unknown code is
 * linked to an item and its container (or a physical item and its pack, or
 * one piece), with an optional brand, and remembered (POST scan/link,
 * inventory.manage). The scan then carries on as if the code was known.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import type { Ingredient } from '@/lib/api/inventory';
import type { PhysicalItem } from '@/lib/api/physicalItems';
import { linkScannedCode, type ScanItemType, type ScanResult } from '@/lib/api/inventoryCodes';

const props = withDefaults(defineProps<{
    code: string | null;
    ingredients: Ingredient[];
    physicalItems?: PhysicalItem[];
    /** Which kinds of item this screen takes. */
    itemTypes?: ScanItemType[];
}>(), { physicalItems: () => [], itemTypes: () => ['ingredient', 'physical'] });

const emit = defineEmits<{
    (e: 'linked', result: ScanResult): void;
    (e: 'close'): void;
}>();

const { t, locale } = useI18n();
const isAr = computed(() => locale.value === 'ar');

const itemKey = ref('');
const containerUuid = ref('');
const label = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

watch(() => props.code, () => {
    itemKey.value = '';
    containerUuid.value = '';
    label.value = '';
    error.value = null;
});

const pickedIngredient = computed<Ingredient | null>(() => {
    const [kind, uuid] = itemKey.value.split(':');
    return kind === 'ingredient' ? (props.ingredients.find((i) => i.uuid === uuid) ?? null) : null;
});
const pickedPhysical = computed<PhysicalItem | null>(() => {
    const [kind, uuid] = itemKey.value.split(':');
    return kind === 'physical' ? (props.physicalItems.find((i) => i.uuid === uuid) ?? null) : null;
});

function nameOf(item: { name: string; name_ar: string | null }): string {
    return isAr.value && item.name_ar ? item.name_ar : item.name;
}

async function submit(): Promise<void> {
    if (!props.code || itemKey.value === '') return;
    const [kind, uuid] = itemKey.value.split(':') as [ScanItemType, string];
    busy.value = true;
    error.value = null;
    try {
        const res = await linkScannedCode(props.code, {
            item_type: kind,
            item_uuid: uuid,
            container_uuid: kind === 'ingredient' && containerUuid.value !== '' ? containerUuid.value : null,
            pack_uuid: kind === 'physical' && containerUuid.value !== '' ? containerUuid.value : null,
            label: label.value.trim() || null,
        });
        emit('linked', res.data);
    } catch (e) {
        error.value = e instanceof ApiError ? (e.firstValidationMessage() ?? e.message) : t('scan.error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BaseModal v-if="code" :title="t('scan.link_title')" size="md" :loading="busy" @close="emit('close')">
        <form id="scan-link-form" class="space-y-3" data-test="scan-link-dialog" @submit.prevent="submit">
            <p class="text-sm text-slate-600">{{ t('scan.link_hint', { code }) }}</p>
            <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ error }}</p>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('scan.item') }} *</span>
                <select v-model="itemKey" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm" @change="containerUuid = ''">
                    <option value="" disabled>{{ t('scan.pick_item') }}</option>
                    <optgroup v-if="itemTypes.includes('ingredient')" :label="t('inventory.tabs.ingredients')">
                        <option v-for="i in ingredients" :key="i.uuid" :value="`ingredient:${i.uuid}`">{{ nameOf(i) }}</option>
                    </optgroup>
                    <optgroup v-if="itemTypes.includes('physical') && physicalItems.length > 0" :label="t('inventory.tabs.physical_items')">
                        <option v-for="p in physicalItems" :key="p.uuid" :value="`physical:${p.uuid}`">{{ nameOf(p) }}</option>
                    </optgroup>
                </select>
            </label>
            <label v-if="pickedIngredient" class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('scan.container') }}</span>
                <select v-model="containerUuid" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm" data-test="scan-link-container">
                    <option value="">{{ t('scan.item_itself') }}</option>
                    <option v-for="c in pickedIngredient.alt_units ?? []" :key="c.uuid" :value="c.uuid">{{ isAr && c.display_name_ar ? c.display_name_ar : (c.display_name ?? c.name) }}</option>
                </select>
            </label>
            <label v-if="pickedPhysical" class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('scan.pack') }}</span>
                <select v-model="containerUuid" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm">
                    <option value="">{{ t('scan.one_piece') }}</option>
                    <option v-for="p in pickedPhysical.packs ?? []" :key="p.uuid" :value="p.uuid">{{ isAr ? p.display_name_ar : p.display_name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">{{ t('scan.brand') }}</span>
                <input v-model="label" type="text" maxlength="80" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm">
            </label>
        </form>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="emit('close')">{{ t('common.cancel') }}</button>
                <button type="submit" form="scan-link-form" :disabled="busy || itemKey === ''" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50">
                    {{ busy ? t('scan.linking') : t('scan.link_submit') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>

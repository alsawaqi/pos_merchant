<script setup lang="ts">
/**
 * LAUNCH review add-on (F) — the scan box. Any USB or Bluetooth scanner types
 * into the focused box and ends with Enter; the code is looked up (container
 * / pack barcode → product barcode → SKU, company-scoped) and `found` is
 * emitted for the screen to add "Milk · 1 × bottle 1.5 l" (the same scan
 * again makes it 2) or to open the item.
 *
 *   mode "add"     Purchases, Transfers, Counts, Waste, Restock requests
 *   mode "search"  the Ingredients / Physical items / Branch stock lists: what
 *                  is typed filters the list (v-model), Enter scans it
 *
 * An unknown code asks which item it is (inventory.manage) and remembers it;
 * a user who may only view sees "Unknown barcode". Step 11 (tester call) —
 * only for a REAL scan: the scanner's fast burst ending in Enter, or a typed
 * EAN / UPC (8–14 digits). Plain text plus Enter never opens the dialog; in a
 * list search typing only filters (lib/scanDetect.ts).
 */
import { ScanLine } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { nextTimes, scanDecision, wasScanned } from '@/lib/scanDetect';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import type { Ingredient } from '@/lib/api/inventory';
import type { PhysicalItem } from '@/lib/api/physicalItems';
import { scanCode, type ScanItemType, type ScanResult } from '@/lib/api/inventoryCodes';
import ScanLinkDialog from './ScanLinkDialog.vue';

const props = withDefaults(defineProps<{
    mode?: 'add' | 'search';
    modelValue?: string;
    canLink?: boolean;
    ingredients?: Ingredient[];
    physicalItems?: PhysicalItem[];
    itemTypes?: ScanItemType[];
    autofocus?: boolean;
    placeholder?: string;
}>(), {
    mode: 'add',
    modelValue: '',
    canLink: false,
    ingredients: () => [],
    physicalItems: () => [],
    itemTypes: () => ['ingredient', 'physical'],
    autofocus: false,
    placeholder: '',
});

const emit = defineEmits<{
    (e: 'found', result: ScanResult): void;
    (e: 'update:modelValue', value: string): void;
}>();

const { t } = useI18n();
const text = ref(props.modelValue);
const busy = ref(false);
const message = ref<string | null>(null);
const linkCode = ref<string | null>(null);
// Step 11 (tester call) — the keystroke times of the text in the box, to tell a scanner burst from typing.
let keyTimes: number[] = [];

// A list search cleared by the page clears the box too.
watch(() => props.modelValue, (value) => {
    if (props.mode === 'search' && value !== text.value) {
        text.value = value;
        keyTimes = [];
    }
});

function onInput(value: string): void {
    keyTimes = nextTimes(keyTimes, Date.now(), text.value.length, value.length);
    text.value = value;
    message.value = null;
    if (props.mode === 'search') emit('update:modelValue', value);
}

async function lookUp(): Promise<void> {
    const code = text.value.trim();
    if (code === '' || busy.value) return;
    // Step 11 — only a real scan (the scanner's burst) or a typed EAN / UPC
    // (8–14 digits) that matches nothing may open "Which item is this barcode?".
    const scanned = wasScanned(keyTimes, text.value);
    busy.value = true;
    message.value = null;
    try {
        const res = await scanCode(code);
        // Fix order B-1 (T1, L9) — the decision is the pure lib/scanDetect
        // scanDecision the node tests run: plain text + Enter never links.
        const decision = scanDecision({
            found: res.data.found,
            itemType: res.data.item_type ?? null,
            allowedTypes: props.itemTypes,
            canLink: props.canLink,
            serverCanLink: res.data.can_link,
            code,
            scanned,
            mode: props.mode,
        });
        if (decision === 'deliver') {
            deliver(res.data);
        } else if (decision === 'wrong_item') {
            message.value = t('scan.wrong_item', { item: res.data.item?.name ?? '' });
        } else if (decision === 'link') {
            linkCode.value = code;
        } else if (decision === 'say_unknown') {
            message.value = props.mode === 'search' ? t('scan.no_match', { code }) : t('scan.unknown', { code });
        }
    } catch (e) {
        message.value = e instanceof ApiError ? e.message : t('scan.error');
    } finally {
        busy.value = false;
    }
}

function deliver(result: ScanResult): void {
    if (result.item_type && !props.itemTypes.includes(result.item_type)) {
        message.value = t('scan.wrong_item', { item: result.item?.name ?? '' });
        return;
    }
    emit('found', result);
    text.value = '';
    keyTimes = [];
    if (props.mode === 'search') emit('update:modelValue', '');
}

function onLinked(result: ScanResult): void {
    linkCode.value = null;
    deliver(result);
}
</script>

<template>
    <div>
        <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-100">
            <ScanLine class="size-4 shrink-0 text-slate-400" />
            <input
                :value="text"
                type="text"
                inputmode="text"
                autocomplete="off"
                :autofocus="autofocus"
                :placeholder="placeholder || (mode === 'search' ? t('scan.search_placeholder') : t('scan.placeholder'))"
                class="w-full border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0"
                data-test="scan-box"
                @input="onInput(($event.target as HTMLInputElement).value)"
                @keydown.enter.prevent="lookUp"
            >
        </label>
        <p v-if="message" class="mt-1 text-xs font-medium text-amber-700" data-test="scan-message">{{ message }}</p>
        <ScanLinkDialog
            :code="linkCode"
            :ingredients="ingredients"
            :physical-items="physicalItems"
            :item-types="itemTypes"
            @linked="onLinked"
            @close="linkCode = null"
        />
    </div>
</template>

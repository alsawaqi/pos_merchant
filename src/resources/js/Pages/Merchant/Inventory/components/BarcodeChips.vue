<script setup lang="ts">
/**
 * LAUNCH review add-on (A5) — the barcodes of a container, an item or a pack
 * as chips, with a box to add one (a scanner types into it and ends with
 * Enter). Kept as typed: leading zeros stay. The parent saves (a draft list on
 * a new item, the barcode endpoints on a saved one).
 */
import { Barcode, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps<{
    barcodes: { uuid?: string; barcode: string; label?: string | null }[];
    editable?: boolean;
    busy?: boolean;
    error?: string | null;
    /**
     * Fix order B-1 (M2) — the code just typed is on another live item: its
     * message, and a button to take it off there and put it here.
     */
    conflict?: { code: string; message: string } | null;
}>();

const emit = defineEmits<{
    (e: 'add', code: string): void;
    (e: 'remove', index: number): void;
    (e: 'move'): void;
}>();

const { t } = useI18n();
const draft = ref('');

function add(): void {
    const code = draft.value.trim();
    if (code === '') return;
    emit('add', code);
    draft.value = '';
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5" data-test="barcode-chips">
        <span v-for="(b, i) in barcodes" :key="b.uuid ?? `${b.barcode}-${i}`" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
            <Barcode class="size-3 text-slate-400" />
            <bdi dir="ltr">{{ b.barcode }}</bdi>
            <span v-if="b.label" class="text-slate-400">· {{ b.label }}</span>
            <button v-if="editable" type="button" class="rounded-full p-0.5 text-slate-400 transition hover:bg-slate-200 hover:text-rose-600" :title="t('containers.remove_barcode')" :disabled="busy" @click="emit('remove', i)">
                <X class="size-3" />
            </button>
        </span>
        <span v-if="editable" class="inline-flex items-center gap-1">
            <input
                v-model="draft"
                type="text"
                maxlength="64"
                :placeholder="t('containers.barcode_placeholder')"
                class="w-36 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs"
                data-test="barcode-input"
                @keydown.enter.prevent="add"
            >
            <button type="button" :disabled="busy || draft.trim() === ''" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" @click="add">
                {{ t('containers.add_barcode') }}
            </button>
        </span>
        <p v-if="error" class="basis-full text-[11px] text-rose-600">{{ error }}</p>
        <p v-if="conflict && editable" class="basis-full text-[11px] text-rose-600" data-test="barcode-conflict">
            {{ conflict.message }}
            <button type="button" :disabled="busy" class="ms-1 rounded border border-rose-200 bg-white px-1.5 py-0.5 font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50" data-test="barcode-move" @click="emit('move')">
                {{ t('containers.move_barcode', { code: conflict.code }) }}
            </button>
        </p>
    </div>
</template>

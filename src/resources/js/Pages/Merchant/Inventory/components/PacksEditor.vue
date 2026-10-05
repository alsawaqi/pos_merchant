<script setup lang="ts">
/**
 * LAUNCH review add-on (D3) — a physical item's packs: "box holds 50 cups",
 * nested "carton holds 4 × box 50". Used by Purchases (a pack line becomes
 * pieces) and the scan box. Barcodes per pack, and per piece of the item.
 * A used pack's size is locked (its name stays editable). Saved rows only:
 * a new item is saved first.
 */
import { Check, Plus, Trash2 } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import { createBarcode, deleteBarcode } from '@/lib/api/inventoryCodes';
import { createPack, deletePack, listPacks, updatePack, type PhysicalItemPack } from '@/lib/api/physicalItems';
import type { ItemBarcodeSummary } from '@/lib/api/inventory';
import BarcodeChips from './BarcodeChips.vue';

const props = withDefaults(defineProps<{
    itemUuid: string | null;
    /** Barcodes on one piece of the item. */
    pieceBarcodes?: ItemBarcodeSummary[];
    canManage?: boolean;
}>(), { pieceBarcodes: () => [], canManage: true });

const emit = defineEmits<{ (e: 'changed'): void }>();

const { t, locale } = useI18n();

const packs = ref<PhysicalItemPack[]>([]);
const pieceCodes = ref<ItemBarcodeSummary[]>([]);
const busy = ref<string | null>(null);
const error = ref<string | null>(null);
const edits = reactive<Record<string, { name: string; name_ar: string; pieces: string }>>({});
const fresh = reactive<{ name: string; name_ar: string; mode: 'pieces' | 'nested'; pieces: string; contains_pack_uuid: string; contains_quantity: string }>({
    name: '', name_ar: '', mode: 'pieces', pieces: '', contains_pack_uuid: '', contains_quantity: '',
});

function sync(): void {
    for (const k of Object.keys(edits)) delete edits[k];
    for (const p of packs.value) edits[p.uuid] = { name: p.name, name_ar: p.name_ar ?? '', pieces: p.pieces };
}

async function load(): Promise<void> {
    if (!props.itemUuid) return;
    try {
        packs.value = (await listPacks(props.itemUuid)).data;
        sync();
    } catch (e) {
        error.value = e instanceof Error ? e.message : t('containers.errors.load_failed');
    }
}

watch(() => props.itemUuid, () => {
    pieceCodes.value = [...props.pieceBarcodes];
    void load();
}, { immediate: true });

function fail(e: unknown): void {
    error.value = e instanceof ApiError ? (e.firstValidationMessage() ?? e.message) : (e instanceof Error ? e.message : t('containers.errors.save_failed'));
}

async function run(key: string, fn: () => Promise<unknown>): Promise<void> {
    busy.value = key;
    error.value = null;
    try {
        await fn();
        await load();
        emit('changed');
    } catch (e) {
        fail(e);
    } finally {
        busy.value = null;
    }
}

function add(): void {
    if (!props.itemUuid) return;
    const uuid = props.itemUuid;
    void run('', async () => {
        await createPack(uuid, fresh.mode === 'nested'
            ? { name: fresh.name.trim(), name_ar: fresh.name_ar.trim() || null, contains_pack_uuid: fresh.contains_pack_uuid, contains_quantity: fresh.contains_quantity }
            : { name: fresh.name.trim(), name_ar: fresh.name_ar.trim() || null, pieces: fresh.pieces });
        Object.assign(fresh, { name: '', name_ar: '', mode: 'pieces', pieces: '', contains_pack_uuid: '', contains_quantity: '' });
    });
}

function save(pack: PhysicalItemPack): void {
    if (!props.itemUuid) return;
    const uuid = props.itemUuid;
    const edit = edits[pack.uuid];
    if (!edit) return;
    void run(pack.uuid, () => updatePack(uuid, pack.uuid, {
        name: edit.name.trim(),
        name_ar: edit.name_ar.trim() || null,
        ...(pack.size_locked || pack.contains_pack_uuid ? {} : { pieces: edit.pieces }),
    }));
}

function remove(pack: PhysicalItemPack): void {
    if (!props.itemUuid || !window.confirm(t('containers.packs.delete_confirm'))) return;
    const uuid = props.itemUuid;
    void run(pack.uuid, () => deletePack(uuid, pack.uuid));
}

function addCode(packUuid: string | null, code: string): void {
    if (!props.itemUuid) return;
    const uuid = props.itemUuid;
    busy.value = packUuid ?? 'piece';
    error.value = null;
    createBarcode(code, { item_type: 'physical', item_uuid: uuid, pack_uuid: packUuid })
        .then(async (res) => {
            if (packUuid === null) pieceCodes.value = [...pieceCodes.value, res.data];
            await load();
            emit('changed');
        })
        .catch(fail)
        .finally(() => { busy.value = null; });
}

function removeCode(list: ItemBarcodeSummary[], index: number, piece: boolean): void {
    const code = list[index];
    if (!code) return;
    busy.value = code.uuid;
    deleteBarcode(code.uuid)
        .then(async () => {
            if (piece) pieceCodes.value = pieceCodes.value.filter((c) => c.uuid !== code.uuid);
            await load();
            emit('changed');
        })
        .catch(fail)
        .finally(() => { busy.value = null; });
}

function label(p: PhysicalItemPack): string {
    return locale.value === 'ar' ? p.display_name_ar : p.display_name;
}
</script>

<template>
    <fieldset class="rounded-lg border border-slate-200 p-3" data-test="packs-editor">
        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('containers.packs.title') }}</legend>
        <p class="mb-2 text-xs text-slate-500">{{ t('containers.packs.hint') }}</p>
        <p v-if="!itemUuid" class="text-xs italic text-slate-500">{{ t('containers.packs.save_first') }}</p>
        <template v-else>
            <div v-if="error" class="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ error }}</div>
            <div class="mb-2">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('containers.packs.piece_barcodes') }}</span>
                <BarcodeChips :barcodes="pieceCodes" :editable="canManage" :busy="busy !== null" @add="addCode(null, $event)" @remove="removeCode(pieceCodes, $event, true)" />
            </div>
            <ul class="space-y-2">
                <li v-for="p in packs" :key="p.uuid" class="space-y-2 rounded border border-slate-200 bg-slate-50/50 p-2" data-test="pack-row">
                    <span class="block text-xs font-semibold text-slate-800">{{ label(p) }}</span>
                    <div v-if="edits[p.uuid]" class="flex flex-wrap items-end gap-2">
                        <input v-model="edits[p.uuid].name" type="text" maxlength="32" :disabled="!canManage" class="min-w-[8rem] flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                        <input v-model="edits[p.uuid].name_ar" type="text" dir="rtl" maxlength="32" :disabled="!canManage" class="w-32 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                        <template v-if="!p.contains_pack_uuid">
                            <span class="pb-1.5 text-sm text-slate-600">{{ t('containers.holds') }}</span>
                            <input v-model="edits[p.uuid].pieces" type="number" step="1" min="2" :disabled="!canManage || p.size_locked === true" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums disabled:bg-slate-100">
                            <span class="pb-1.5 text-sm text-slate-600">{{ t('containers.packs.pieces') }}</span>
                        </template>
                        <template v-if="canManage">
                            <button type="button" :disabled="busy === p.uuid" class="inline-flex h-8 items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-2.5 text-xs font-semibold text-teal-700" @click="save(p)">
                                <Check class="size-3.5" /> {{ t('containers.save') }}
                            </button>
                            <button type="button" :disabled="busy === p.uuid" class="grid size-8 place-items-center rounded-lg border border-rose-200 text-rose-700" :title="t('containers.remove')" @click="remove(p)">
                                <Trash2 class="size-4" />
                            </button>
                        </template>
                    </div>
                    <p v-if="p.size_locked" class="text-[11px] text-slate-500">{{ t('containers.size_locked') }}</p>
                    <BarcodeChips :barcodes="p.barcodes" :editable="canManage" :busy="busy !== null" @add="addCode(p.uuid, $event)" @remove="removeCode(p.barcodes, $event, false)" />
                </li>
            </ul>
            <div v-if="canManage" class="mt-2 flex flex-wrap items-center gap-2 rounded border border-teal-100 bg-teal-50/40 p-2 text-sm" data-test="pack-new">
                <input v-model="fresh.name" type="text" maxlength="32" :placeholder="t('containers.packs.name_placeholder')" class="min-w-[8rem] flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                <input v-model="fresh.name_ar" type="text" dir="rtl" maxlength="32" :placeholder="t('containers.name_ar')" class="w-32 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm">
                <span class="text-slate-600">{{ t('containers.holds') }}</span>
                <select v-model="fresh.mode" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                    <option value="pieces">{{ t('containers.packs.mode_pieces') }}</option>
                    <option value="nested" :disabled="packs.length === 0">{{ t('containers.packs.mode_nested') }}</option>
                </select>
                <template v-if="fresh.mode === 'pieces'">
                    <input v-model="fresh.pieces" type="number" step="1" min="2" placeholder="50" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                </template>
                <template v-else>
                    <input v-model="fresh.contains_quantity" type="number" step="1" min="2" placeholder="4" class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums">
                    <span>×</span>
                    <select v-model="fresh.contains_pack_uuid" class="min-w-[9rem] rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                        <option value="" disabled>{{ t('containers.pick_content') }}</option>
                        <option v-for="p in packs" :key="p.uuid" :value="p.uuid">{{ label(p) }}</option>
                    </select>
                </template>
                <button type="button" :disabled="busy !== null || !fresh.name.trim()" class="ms-auto inline-flex h-8 items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-700 disabled:opacity-60" @click="add">
                    <Plus class="size-3.5" /> {{ t('containers.packs.add') }}
                </button>
            </div>
        </template>
    </fieldset>
</template>

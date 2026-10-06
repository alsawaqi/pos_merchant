<script setup lang="ts">
/**
 * LAUNCH packaging add-on — the Order packaging tab of the Inventory page
 * (owner decision 3). One list per order type — Dine in, Quick order, To go,
 * Delivery — set once for the whole merchant. Each line is an ingredient (a
 * unit of its kind, with the live translation and "Is this right?") or a
 * physical item in pieces or packs. When an order is paid (or a delivery is
 * handed over), the list of its final type is taken from stock ONCE for the
 * whole order. Reading needs catalogue or inventory view; changing a list
 * needs "Edit recipes". The scan box adds the scanned item to the chosen list.
 */
import { Package, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import type { Ingredient } from '@/lib/api/inventory';
import type { PhysicalItem } from '@/lib/api/physicalItems';
import { getOrderPackaging, saveOrderPackaging, type OrderPackagingState } from '@/lib/api/orderPackaging';
import type { ScanResult } from '@/lib/api/inventoryCodes';
import { recipeLineWarning, type AmountWarning } from '@/lib/amountSafety';
import { containerToken } from '@/lib/containers';
import { ORDER_TYPE_BUCKETS, type OrderTypeBucket } from '@/lib/orderTypes';
import { amountMissing, applyPackagingScan, blankDraft, draftOf, duplicateLines, payloadOf, type PackagingDraft } from '@/lib/orderPackaging';
import { lineAmountText, money, recipeUnitFactor, recipeUnitOptions } from '@/lib/recipeUnits';
import { useAmountConfirm } from '@/composables/useAmountConfirm';
import AmountConfirmDialog from './components/AmountConfirmDialog.vue';
import AmountInput from './components/AmountInput.vue';
import ScanBox from './components/ScanBox.vue';

const props = withDefaults(defineProps<{
    ingredients: Ingredient[];
    physicalItems: PhysicalItem[];
    /** inventory.manage — the scan box may link an unknown barcode. */
    canLink?: boolean;
}>(), {
    canLink: false,
});

const { t, locale } = useI18n();
const isArabic = computed(() => locale.value === 'ar');

const state = ref<OrderPackagingState | null>(null);
const loading = ref(true);
const failed = ref(false);
const drafts = reactive<Record<OrderTypeBucket, PackagingDraft[]>>({ dine_in: [], quick: [], to_go: [], delivery: [] });
const busy = reactive<Record<OrderTypeBucket, boolean>>({ dine_in: false, quick: false, to_go: false, delivery: false });
const errors = reactive<Record<OrderTypeBucket, string | null>>({ dine_in: null, quick: null, to_go: null, delivery: null });
const saved = reactive<Record<OrderTypeBucket, boolean>>({ dine_in: false, quick: false, to_go: false, delivery: false });
const scanTarget = ref<OrderTypeBucket>('to_go');
const scanMessage = ref<string | null>(null);

const canEdit = computed(() => state.value?.can_edit === true);
// Packaging is never cooked: raw ingredients only; physical items used with food only.
const rawIngredients = computed(() => props.ingredients.filter((i) => !i.is_prep));
const packagingItems = computed(() => props.physicalItems.filter((p) => p.purpose !== 'general'));

const { warnings: amountWarnings, confirm: confirmAmounts, answer: answerAmounts } = useAmountConfirm();

function load(next: OrderPackagingState): void {
    state.value = next;
    for (const bucket of ORDER_TYPE_BUCKETS) {
        drafts[bucket] = next.lists[bucket].lines.map(draftOf);
    }
}

onMounted(async () => {
    try {
        load((await getOrderPackaging()).data);
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});

function ingredientOf(uuid: string): Ingredient | undefined {
    return rawIngredients.value.find((i) => i.uuid === uuid) ?? props.ingredients.find((i) => i.uuid === uuid);
}

function itemOf(uuid: string): PhysicalItem | undefined {
    return props.physicalItems.find((p) => p.uuid === uuid);
}

function itemName(name: string, nameAr: string | null | undefined): string {
    return isArabic.value && nameAr ? nameAr : name;
}

/** A physical item's units: pieces, or one of its packs (by the pack's token). */
function packOptions(uuid: string): { value: string; label: string }[] {
    const item = itemOf(uuid);
    return [
        { value: '', label: t('order_packaging.pieces') },
        ...(item?.packs ?? []).map((p) => ({ value: containerToken(p.uuid), label: isArabic.value ? p.display_name_ar : p.display_name })),
    ];
}

/** Pieces a product line takes (a pack × its pieces). */
function piecesOf(draft: PackagingDraft): number {
    const n = Number(draft.quantity);
    if (draft.unit === '') return n;
    const pack = (itemOf(draft.product_uuid)?.packs ?? []).find((p) => containerToken(p.uuid) === draft.unit);
    return pack ? n * Number(pack.pieces) : n;
}

function setType(bucket: OrderTypeBucket, idx: number, type: 'ingredient' | 'product'): void {
    drafts[bucket][idx] = { ...blankDraft(type), quantity: drafts[bucket][idx]?.quantity ?? '1' };
}

function addLine(bucket: OrderTypeBucket): void {
    drafts[bucket].push(blankDraft());
    saved[bucket] = false;
}

function removeLine(bucket: OrderTypeBucket, idx: number): void {
    drafts[bucket].splice(idx, 1);
    saved[bucket] = false;
}

function duplicates(bucket: OrderTypeBucket): number[] {
    return duplicateLines(drafts[bucket]);
}

function blocked(bucket: OrderTypeBucket): boolean {
    return duplicates(bucket).length > 0 || drafts[bucket].some(amountMissing);
}

/** Read-only line: "10 g Sugar", "1 × pack 50 Napkin". */
function readonlyText(bucket: OrderTypeBucket, idx: number): string {
    const line = state.value?.lists[bucket].lines[idx];
    if (!line) return '';
    if (line.type === 'ingredient') {
        const ingredient = ingredientOf(line.ingredient_uuid ?? '');
        const draft = draftOf(line);
        return `${lineAmountText(ingredient, draft.unit, draft.quantity, locale.value)} ${itemName(line.name ?? '', line.name_ar)}`;
    }
    return `${Number(line.quantity)} × ${itemName(line.name ?? '', line.name_ar)}`;
}

/** E2 — "One order would use 200 l. Did you mean 200 ml?" (warns, never blocks). */
function warningsOf(bucket: OrderTypeBucket): (AmountWarning | null)[] {
    return drafts[bucket].map((d) => {
        let warning: AmountWarning | null = null;
        if (d.type === 'ingredient') {
            const ingredient = ingredientOf(d.ingredient_uuid);
            if (!ingredient) return null;
            warning = recipeLineWarning({ amount: d.quantity, unit: d.unit, storedUnit: ingredient.unit, factor: recipeUnitFactor(ingredient, d.unit) });
        } else if (d.product_uuid !== '') {
            warning = recipeLineWarning({ amount: piecesOf(d), unit: '', storedUnit: 'piece' });
        }
        return warning === null ? null : { key: warning.key.replace('amount_safety.warnings.', 'order_packaging.warnings.'), params: warning.params };
    });
}

async function save(bucket: OrderTypeBucket): Promise<void> {
    if (!canEdit.value || busy[bucket] || blocked(bucket)) return;
    if (!(await confirmAmounts(warningsOf(bucket)))) return;
    busy[bucket] = true;
    errors[bucket] = null;
    saved[bucket] = false;
    try {
        const response = await saveOrderPackaging(bucket, payloadOf(drafts[bucket]));
        const keep = { ...drafts };
        load(response.data);
        // The other lists keep what is being typed in them.
        for (const other of ORDER_TYPE_BUCKETS) {
            if (other !== bucket) drafts[other] = keep[other];
        }
        saved[bucket] = true;
    } catch (err) {
        const message = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? '')
            : '';
        errors[bucket] = message !== '' ? message : t('order_packaging.save_failed');
    } finally {
        busy[bucket] = false;
    }
}

/** F — the scanned item goes on the chosen list (the same code again makes it 2). */
function onScan(result: ScanResult): void {
    scanMessage.value = null;
    if (!canEdit.value) return;
    const outcome = applyPackagingScan(drafts[scanTarget.value], result);
    if (!outcome.ok) {
        scanMessage.value = t(`order_packaging.scan.${outcome.reason}`);
        return;
    }
    drafts[scanTarget.value] = outcome.drafts;
    saved[scanTarget.value] = false;
}
</script>

<template>
    <section class="space-y-4" data-test="order-packaging-tab">
        <p class="max-w-3xl text-xs text-slate-500">{{ t('order_packaging.subtitle') }}</p>

        <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">{{ t('common.loading') }}</div>
        <div v-else-if="failed" class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">{{ t('order_packaging.load_failed') }}</div>
        <template v-else-if="state">
            <p v-if="!canEdit" class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800" data-test="order-packaging-readonly">{{ t('order_packaging.readonly_hint') }}</p>

            <!-- F — the scan box adds to the chosen list. -->
            <div v-if="canEdit" class="flex flex-wrap items-end gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="block">
                    <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('order_packaging.scan_into') }}</span>
                    <select v-model="scanTarget" class="mt-1 block rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs" data-test="order-packaging-scan-target">
                        <option v-for="bucket in ORDER_TYPE_BUCKETS" :key="bucket" :value="bucket">{{ t(`order_types.long.${bucket}`) }}</option>
                    </select>
                </label>
                <ScanBox class="min-w-64 flex-1" :can-link="canLink" :ingredients="rawIngredients" :physical-items="packagingItems" data-test="order-packaging-scan" @found="onScan" />
                <p v-if="scanMessage" class="basis-full text-xs font-semibold text-rose-700" data-test="order-packaging-scan-message">{{ scanMessage }}</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <article v-for="bucket in ORDER_TYPE_BUCKETS" :key="bucket" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" :data-test="`order-packaging-${bucket}`">
                    <header class="flex items-start justify-between gap-2">
                        <h3 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <Package class="size-4 text-sky-600" />
                            {{ t(`order_types.long.${bucket}`) }}
                        </h3>
                        <span class="text-[11px] tabular-nums text-slate-500" :data-test="`order-packaging-cost-${bucket}`">
                            {{ t('order_packaging.cost_per_order') }}: <strong class="text-slate-800">{{ money(state.lists[bucket].cost) }}</strong> OMR
                            <span v-if="!state.lists[bucket].cost_complete" class="font-semibold text-amber-700">· {{ t('purchases_v2.cost_incomplete') }}</span>
                        </span>
                    </header>

                    <!-- Read-only -->
                    <template v-if="!canEdit">
                        <p v-if="state.lists[bucket].lines.length === 0" class="mt-2 text-xs italic text-slate-500">{{ t('order_packaging.empty') }}</p>
                        <ul v-else class="mt-2 space-y-1">
                            <li v-for="(line, idx) in state.lists[bucket].lines" :key="idx" class="text-sm text-slate-700"><bdi dir="ltr" class="tabular-nums">{{ readonlyText(bucket, idx) }}</bdi></li>
                        </ul>
                    </template>

                    <!-- Editable -->
                    <template v-else>
                        <p v-if="drafts[bucket].length === 0" class="mt-2 text-xs italic text-slate-500">{{ t('order_packaging.empty') }}</p>
                        <ul v-else class="mt-2 space-y-2">
                            <li v-for="(line, idx) in drafts[bucket]" :key="idx" class="flex flex-wrap items-start gap-2 rounded-lg border border-slate-100 bg-slate-50/60 p-2">
                                <select :value="line.type" class="w-28 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs" :aria-label="t('order_packaging.kind')" @change="setType(bucket, idx, ($event.target as HTMLSelectElement).value as 'ingredient' | 'product')">
                                    <option value="ingredient">{{ t('order_packaging.kind_ingredient') }}</option>
                                    <option value="product">{{ t('order_packaging.kind_item') }}</option>
                                </select>
                                <select v-if="line.type === 'ingredient'" v-model="line.ingredient_uuid" class="min-w-40 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs" :aria-label="t('order_packaging.kind_ingredient')" @change="line.unit = ''">
                                    <option value="">—</option>
                                    <option v-for="ing in rawIngredients" :key="ing.uuid" :value="ing.uuid">{{ itemName(ing.name, ing.name_ar) }} ({{ ing.unit }})</option>
                                </select>
                                <select v-else v-model="line.product_uuid" class="min-w-40 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs" :aria-label="t('order_packaging.kind_item')" @change="line.unit = ''">
                                    <option value="">—</option>
                                    <option v-for="item in packagingItems" :key="item.uuid" :value="item.uuid">{{ itemName(item.name, item.name_ar) }}</option>
                                </select>
                                <div class="w-56">
                                    <AmountInput
                                        v-if="line.type === 'ingredient'"
                                        v-model="line.quantity"
                                        v-model:unit="line.unit"
                                        :options="recipeUnitOptions(ingredientOf(line.ingredient_uuid), locale)"
                                        :stored-unit="ingredientOf(line.ingredient_uuid)?.unit ?? 'g'"
                                        :containers="ingredientOf(line.ingredient_uuid)?.alt_units ?? []"
                                        step="0.0001"
                                        input-class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs tabular-nums"
                                        select-class="w-32 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs"
                                        data-test="order-packaging-amount"
                                    />
                                    <AmountInput
                                        v-else
                                        v-model="line.quantity"
                                        v-model:unit="line.unit"
                                        :options="packOptions(line.product_uuid)"
                                        stored-unit="piece"
                                        step="1"
                                        input-class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs tabular-nums"
                                        select-class="w-32 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs"
                                        data-test="order-packaging-pieces"
                                    />
                                </div>
                                <button type="button" class="grid size-8 place-items-center rounded-lg border border-rose-200 text-rose-600 transition hover:bg-rose-50" :title="t('common.delete')" @click="removeLine(bucket, idx)">
                                    <Trash2 class="size-3.5" />
                                </button>
                                <p v-if="line.type === 'product' && line.unit !== '' && line.product_uuid" class="basis-full text-[11px] text-teal-700">{{ t('order_packaging.pieces_total', { n: piecesOf(line) }) }}</p>
                                <p v-if="duplicates(bucket).includes(idx)" class="basis-full text-[11px] font-semibold text-rose-700" data-test="order-packaging-duplicate">{{ t('order_packaging.duplicate') }}</p>
                                <p v-else-if="amountMissing(line)" class="basis-full text-[11px] font-semibold text-rose-700">{{ t('recipe_units.amount_required') }}</p>
                            </li>
                        </ul>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50" :data-test="`order-packaging-add-${bucket}`" @click="addLine(bucket)">
                                <Plus class="size-3" /> {{ t('order_packaging.add_line') }}
                            </button>
                            <button type="button" :disabled="busy[bucket] || blocked(bucket)" class="ms-auto rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60" :data-test="`order-packaging-save-${bucket}`" @click="save(bucket)">
                                {{ busy[bucket] ? t('common.saving') : t('common.save') }}
                            </button>
                        </div>
                        <p v-if="saved[bucket]" class="mt-1 text-[11px] font-semibold text-emerald-700">{{ t('order_packaging.saved') }}</p>
                        <p v-if="errors[bucket]" class="mt-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ errors[bucket] }}</p>
                    </template>
                </article>
            </div>
        </template>

        <AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" />
    </section>
</template>

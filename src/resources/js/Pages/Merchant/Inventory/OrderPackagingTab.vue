<script setup lang="ts">
/**
 * LAUNCH packaging add-on — the Order packaging lists (owner decision 3), on
 * the Inventory page and on their own page for a recipe editor without
 * inventory access. One list per order type — Dine in, Quick order, To go,
 * Delivery — set once for the whole merchant. Each line is an ingredient (a
 * unit of its kind, with the live translation and "Is this right?") or a
 * physical item / bought-in product in pieces or packs. When an order is paid
 * (or a delivery is handed over), the list of its final type is taken from
 * stock ONCE for the whole order. Reading needs catalogue or inventory view;
 * changing a list needs "Edit recipes".
 *
 * Fix order PK-B1 — the item picker comes with the lists (L4), ingredients
 * from the ingredient list (catalogue or inventory view), the scan box only
 * for inventory viewers (its endpoint); server refusals in the page's
 * language (L3); a deleted or inactive item shows "not taken" (M2/M3); only
 * the amount is isolated left-to-right (L6).
 */
import { Package, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listIngredients, type Ingredient } from '@/lib/api/inventory';
import { getOrderPackaging, saveOrderPackaging, type OrderPackagingItem, type OrderPackagingState } from '@/lib/api/orderPackaging';
import type { ScanResult } from '@/lib/api/inventoryCodes';
import { recipeLineWarning, type AmountWarning } from '@/lib/amountSafety';
import { localizedMessage, ORDER_TYPE_BUCKETS, type OrderTypeBucket } from '@/lib/orderTypes';
import {
    amountMissing,
    applyPackagingScan,
    blankDraft,
    draftOf,
    duplicateLines,
    orderWarning,
    packOptions,
    payloadOf,
    piecesOf,
    readonlyParts,
    type PackagingDraft,
} from '@/lib/orderPackaging';
import { lineAmountText, money, recipeUnitFactor, recipeUnitOptions } from '@/lib/recipeUnits';
import { MerchantPermission } from '@/lib/permissions';
import { useAmountConfirm } from '@/composables/useAmountConfirm';
import AmountConfirmDialog from './components/AmountConfirmDialog.vue';
import AmountInput from './components/AmountInput.vue';
import ScanBox from './components/ScanBox.vue';

const props = withDefaults(defineProps<{
    /** inventory.manage — the scan box may link an unknown barcode. */
    canLink?: boolean;
}>(), {
    canLink: false,
});

const { t, locale } = useI18n();
const { can } = usePermissions();

const state = ref<OrderPackagingState | null>(null);
const ingredients = ref<Ingredient[]>([]);
const loading = ref(true);
const failed = ref(false);
const drafts = reactive<Record<OrderTypeBucket, PackagingDraft[]>>({ dine_in: [], quick: [], to_go: [], delivery: [] });
const busy = reactive<Record<OrderTypeBucket, boolean>>({ dine_in: false, quick: false, to_go: false, delivery: false });
const errors = reactive<Record<OrderTypeBucket, string | null>>({ dine_in: null, quick: null, to_go: null, delivery: null });
const saved = reactive<Record<OrderTypeBucket, boolean>>({ dine_in: false, quick: false, to_go: false, delivery: false });
const scanTarget = ref<OrderTypeBucket>('to_go');
const scanMessage = ref<string | null>(null);

const canEdit = computed(() => state.value?.can_edit === true);
// The scan endpoint needs inventory.view.
const canScan = computed(() => canEdit.value && can(MerchantPermission.InventoryView));
// Packaging is never cooked: raw, active ingredients only.
const rawIngredients = computed(() => ingredients.value.filter((i) => !i.is_prep && i.status === 'active'));
const items = computed<OrderPackagingItem[]>(() => state.value?.items ?? []);

const { warnings: amountWarnings, confirm: confirmAmounts, answer: answerAmounts } = useAmountConfirm();

function load(next: OrderPackagingState): void {
    state.value = next;
    for (const bucket of ORDER_TYPE_BUCKETS) {
        drafts[bucket] = next.lists[bucket].lines.map(draftOf);
    }
}

onMounted(async () => {
    try {
        const [packaging, list] = await Promise.all([getOrderPackaging(), listIngredients().catch(() => ({ data: [] as Ingredient[] }))]);
        ingredients.value = list.data;
        load(packaging.data);
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});

function ingredientOf(uuid: string): Ingredient | undefined {
    return ingredients.value.find((i) => i.uuid === uuid);
}

function itemOf(uuid: string): OrderPackagingItem | undefined {
    return items.value.find((p) => p.uuid === uuid);
}

function itemName(name: string, nameAr: string | null | undefined): string {
    return locale.value === 'ar' && nameAr ? nameAr : name;
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

/** Read-only line: the amount (isolated left-to-right) and the name. */
function readonlyLine(bucket: OrderTypeBucket, idx: number): { amount: string; name: string } {
    const line = state.value?.lists[bucket].lines[idx];
    if (!line) return { amount: '', name: '' };
    return readonlyParts(line, locale.value, (d) => lineAmountText(ingredientOf(d.ingredient_uuid), d.unit, d.quantity, locale.value));
}

/** M2/M3 — a line pos_api would skip (the item was deleted or made inactive before the guards). */
function notTaken(bucket: OrderTypeBucket, idx: number): boolean {
    return state.value?.lists[bucket].lines[idx]?.available === false;
}

/** E2 — "One order would use 200 l. Did you mean 200 ml?" (warns, never blocks). */
function warningsOf(bucket: OrderTypeBucket): (AmountWarning | null)[] {
    return drafts[bucket].map((d) => {
        if (d.type === 'ingredient') {
            const ingredient = ingredientOf(d.ingredient_uuid);
            if (!ingredient) return null;
            return orderWarning(recipeLineWarning({ amount: d.quantity, unit: d.unit, storedUnit: ingredient.unit, factor: recipeUnitFactor(ingredient, d.unit) }));
        }
        return d.product_uuid === '' ? null : orderWarning(recipeLineWarning({ amount: piecesOf(d, itemOf(d.product_uuid)), unit: '', storedUnit: 'piece' }));
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
        const payload = err instanceof ApiError ? err.payload : null;
        const plain = payload && typeof payload === 'object' && 'message' in payload ? String((payload as { message?: unknown }).message ?? '') : '';
        errors[bucket] = localizedMessage(payload, locale.value) ?? (plain !== '' ? plain : t('order_packaging.save_failed'));
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

            <!-- F — the scan box adds to the chosen list (inventory viewers: the scan endpoint). -->
            <div v-if="canScan" class="flex flex-wrap items-end gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="block">
                    <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ t('order_packaging.scan_into') }}</span>
                    <select v-model="scanTarget" class="mt-1 block rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs" data-test="order-packaging-scan-target">
                        <option v-for="bucket in ORDER_TYPE_BUCKETS" :key="bucket" :value="bucket">{{ t(`order_types.long.${bucket}`) }}</option>
                    </select>
                </label>
                <ScanBox class="min-w-64 flex-1" :can-link="props.canLink" :ingredients="rawIngredients" data-test="order-packaging-scan" @found="onScan" />
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
                            <li v-for="(line, idx) in state.lists[bucket].lines" :key="idx" class="text-sm text-slate-700">
                                <bdi dir="ltr" class="tabular-nums">{{ readonlyLine(bucket, idx).amount }}</bdi> {{ readonlyLine(bucket, idx).name }}
                                <span v-if="notTaken(bucket, idx)" class="ms-1 rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700" data-test="order-packaging-not-taken">{{ t('order_packaging.not_taken') }}</span>
                            </li>
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
                                    <option v-for="item in items" :key="item.uuid" :value="item.uuid">{{ itemName(item.name, item.name_ar) }}<template v-if="item.kind === 'bought_in'"> ({{ t('order_packaging.bought_in') }})</template></option>
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
                                        :options="packOptions(itemOf(line.product_uuid), locale, t('order_packaging.pieces'))"
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
                                <p v-if="line.type === 'product' && line.unit !== '' && line.product_uuid" class="basis-full text-[11px] text-teal-700">{{ t('order_packaging.pieces_total', { n: piecesOf(line, itemOf(line.product_uuid)) }) }}</p>
                                <p v-if="duplicates(bucket).includes(idx)" class="basis-full text-[11px] font-semibold text-rose-700" data-test="order-packaging-duplicate">{{ t('order_packaging.duplicate') }}</p>
                                <p v-else-if="amountMissing(line)" class="basis-full text-[11px] font-semibold text-rose-700">{{ t('recipe_units.amount_required') }}</p>
                                <p v-if="notTaken(bucket, idx)" class="basis-full text-[11px] font-semibold text-rose-700" data-test="order-packaging-not-taken">{{ t('order_packaging.not_taken_hint') }}</p>
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
                        <p v-if="errors[bucket]" class="mt-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700" data-test="order-packaging-error">{{ errors[bucket] }}</p>
                    </template>
                </article>
            </div>
        </template>

        <AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" />
    </section>
</template>

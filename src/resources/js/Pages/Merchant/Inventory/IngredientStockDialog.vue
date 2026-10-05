<script setup lang="ts">
/**
 * P-G4 — central warehouse + per-branch distribution for an INGREDIENT, the
 * ingredient twin of Catalogue/ProductStockDialog.vue ("buy 100 kg of sugar
 * once, then split 20/20/25 to branches"). Receive into the warehouse,
 * Receive & Distribute in one step, allocate out to branches, transfer
 * between branches (a real BranchTransfer), adjust a balance, and read the
 * movement history. Balances arrive as decimal strings in the ingredient's
 * stored unit (never parsed for precision-critical math here).
 *
 * LAUNCH item kind, F4 — amounts are typed in any unit the ingredient knows
 * (kg / l, g / ml, a pack size, the count container), picked once for the
 * form and sent as `unit`; the server converts. Balances read the friendly
 * way ("24 l", not "24000.000 ml").
 *
 * Fix order B-2 — Allocate (per branch) and Transfer may be entered BY
 * CONTAINER, like the branch transfer modal: container rows ("3 × bottle
 * 1 l", a crate's inner count lowered for a broken bottle) and a total that
 * may be lowered (3 bottles = 2.5 l), never raised.
 */
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import PurchaseCostFields, { type PurchaseCostModel } from '@/Pages/Merchant/Inventory/PurchaseCostFields.vue';
import { ApiError } from '@/lib/api';
import { authState } from '@/stores/auth';
import {
    adjustIngredientStock,
    allocateIngredientStock,
    getIngredientStock,
    receiveAndDistributeIngredientStock,
    receiveIngredientStock,
    transferIngredientStock,
    type IngredientAllocateLine,
    type IngredientStockSummary,
} from '@/lib/api/ingredientStock';
import { listTaxes, type Tax } from '@/lib/api/taxes';
import type { Ingredient } from '@/lib/api/inventory';
import { ArrowLeftRight } from 'lucide-vue-next';
import { conversionsOf, costUnit, displayAmount, entryUnitOptions, hasConversions } from '@/lib/itemKind';
import { useAmountDisplay } from '@/composables/useAmountDisplay';
import AmountDisplaySwitch from './AmountDisplaySwitch.vue';
// LAUNCH review add-on — B the breakdown and "Correct containers", E1 the live translation.
import { correctWarehouseContainers } from '@/lib/api/ingredientStock';
import { amountInStored, amountProblem, capOf, containerLabel, containerRowsPayload, containersOf, friendly, innerRaised, rowsCap } from '@/lib/containers';
import AmountInput from './components/AmountInput.vue';
import ContainerRows, { type ContainerRowDraft } from './components/ContainerRows.vue';
import StockBreakdown from './components/StockBreakdown.vue';

const props = withDefaults(defineProps<{
    open: boolean;
    ingredientUuid: string | null;
    ingredientName: string;
    /** F4 — the ingredient (its pack sizes and count container) for the amount unit picker. */
    ingredient?: Ingredient | null;
    canManage: boolean;
    /**
     * LAUNCH-P2 P2-4 — stock comes in through Goods received only: the
     * warehouse Receive and Receive & distribute tabs are hidden (and the
     * server refuses them). Allocate, transfer and adjust stay.
     */
    singleStockIn?: boolean;
}>(), { singleStockIn: true, ingredient: null });

const { t, locale } = useI18n();
// G3 — the stock lists' "Show in" choice (shared with the branch stock list).
const { mode: amountDisplay } = useAmountDisplay();

const emit = defineEmits<{ (e: 'close'): void }>();

type Action = 'distribute' | 'receive' | 'allocate' | 'transfer' | 'adjust' | 'containers';

const loading = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionOk = ref<string | null>(null);
const summary = ref<IngredientStockSummary | null>(null);
const action = ref<Action>('distribute');

// P-G5 — a branch-restricted user (branch_scope is a list) can't touch
// the central warehouse: the server 403s receive/distribute/allocate +
// central adjust. Hide those tabs so the dialog only offers what works
// (transfer between their branches + a branch adjust). null = all
// branches = unrestricted = every tab.
const isBranchRestricted = computed(() => Array.isArray(authState.user?.branch_scope));
const availableActions = computed<Action[]>(() =>
    isBranchRestricted.value
        ? ['transfer', 'adjust']
        : props.singleStockIn
            ? ['allocate', 'transfer', 'adjust']
            : ['distribute', 'receive', 'allocate', 'transfer', 'adjust'],
);

/**
 * LAUNCH review add-on (B, tester call 8) — "Correct containers": set the
 * warehouse breakdown to what is on the shelf (the total never moves). Only
 * for accounts with access to all branches, on an item with containers.
 */
const itemContainers = computed(() => containersOf(props.ingredient ?? null));
const shownActions = computed<Action[]>(() =>
    !isBranchRestricted.value && itemContainers.value.length > 0
        ? [...availableActions.value, 'containers']
        : availableActions.value,
);
const correctRows = ref<{ container_uuid: string; label: string; factor: string; pieces: string | number }[]>([]);
const correctNote = ref('');

/** One row per container, filled with the warehouse breakdown as it stands. */
function resetCorrectRows(): void {
    const stored = props.ingredient?.unit ?? unit.value;
    correctRows.value = itemContainers.value.map((c) => ({
        container_uuid: c.uuid,
        label: containerLabel(c, locale.value, stored),
        factor: c.factor,
        pieces: summary.value?.central_breakdown?.find((b) => b.container_uuid === c.uuid)?.pieces ?? '',
    }));
    correctNote.value = '';
}

/** What the corrected containers hold, in the stored unit. */
const correctHolds = computed(() => capOf(correctRows.value.map((r) => ({ factor: r.factor, pieces: String(r.pieces).trim() === '' ? 0 : r.pieces }))));

function doCorrectContainers(): void {
    if (!props.ingredientUuid) return;
    const containers = correctRows.value
        .filter((r) => qty(r.pieces) !== '')
        .map((r) => ({ container_uuid: r.container_uuid, pieces: qty(r.pieces) }));
    void run(
        () => correctWarehouseContainers(props.ingredientUuid as string, { containers, note: correctNote.value || null }),
        t('containers.correct.done'),
    );
}

// Quantity fields are bound to type="number" inputs: Vue's v-model stores a
// NUMBER once a value is typed ('' only while blank) — hence string | number,
// and qty() below normalizes before every trim/parseFloat/wire payload.
const distributeForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
const distributeRows = ref<{ branch_uuid: string; branch_name: string; quantity: string | number }[]>([]);
const receiveForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
// PD5 — the cash-model purchase cost for the receive / receive-and-distribute
// buys (buying ingredients into the warehouse is a purchase = an expense).
function blankCost(): PurchaseCostModel { return { total_cost: '', delivery_cost: '', no_cost: false, tax_amount: 0, tax_rate: null }; }
const receiveCost = ref<PurchaseCostModel>(blankCost());
const distributeCost = ref<PurchaseCostModel>(blankCost());
// PT — active company taxes offered as purchase-tax rates (loaded once).
const taxes = ref<Tax[]>([]);
/**
 * A branch's share: an amount in the dialog's unit, or (fix order B-2) by
 * container — rows, and a total in amount_unit that may be lowered ('' = what
 * the containers hold).
 */
interface AllocateRow {
    branch_uuid: string;
    branch_name: string;
    quantity: string | number;
    containers: ContainerRowDraft[];
    amount: string | number;
    amount_unit: string;
}
const allocateRows = ref<AllocateRow[]>([]);
const allocateNote = ref('');
const transferForm = reactive<{ from_branch_uuid: string; to_branch_uuid: string; quantity: string | number; note: string; containers: ContainerRowDraft[]; amount: string | number; amount_unit: string }>(
    { from_branch_uuid: '', to_branch_uuid: '', quantity: '', note: '', containers: [], amount: '', amount_unit: '' },
);
const adjustForm = reactive<{ branch_uuid: string; signed_quantity: string | number; note: string }>(
    { branch_uuid: '', signed_quantity: '', note: '' },
);

function qty(v: string | number): string {
    return String(v ?? '').trim();
}

const branches = computed(() => summary.value?.branches ?? []);
const unit = computed(() => summary.value?.unit ?? '');

// F4 — the unit every amount in the forms is typed in ('' = the stored unit).
const unitOptions = computed(() => entryUnitOptions(props.ingredient ?? (unit.value ? { unit: unit.value } : null), locale.value));
const entryUnit = ref('');

/** kg / l by default for a Weighed / Liquid item (its stored unit otherwise). */
function defaultEntryUnit(): string {
    const big = costUnit(unit.value);
    return big !== unit.value && unitOptions.value.some((o) => o.value === big) ? big : '';
}

/** The picked unit's name for the labels ("l", "crate", "bottle"). */
const entryUnitName = computed(() => {
    if (entryUnit.value === '') return unit.value;
    const option = unitOptions.value.find((o) => o.value === entryUnit.value);
    return option ? option.label.replace(/ \(.*\)$/, '') : entryUnit.value;
});

function wireUnit(): string | null {
    return entryUnit.value === '' ? null : entryUnit.value;
}

// G2 — the item behind the balances (its pack sizes and container), and which
// balance ('central' or a branch uuid) shows its amount in every unit.
const conversionSource = computed(() => props.ingredient ?? (unit.value ? { unit: unit.value } : null));
const conversionsOpen = ref<string | null>(null);

function toggleConversions(key: string): void {
    conversionsOpen.value = conversionsOpen.value === key ? null : key;
}

/** F4 — a balance as people read it: "24 l", not "24000.000 ml"; G3 — or always kg / l, g / ml. */
function amount(quantity: string | null | undefined): string {
    if (quantity === null || quantity === undefined) return '—';
    const shown = displayAmount(quantity, unit.value, amountDisplay.value);
    return `${shown.amount} ${shown.unit}`;
}

// ---- fix order B-2: by container -----------------------------------

/** The total's unit by container: kg / l for a Weighed / Liquid item (the stored unit otherwise). */
function containerAmountUnit(): string {
    const big = costUnit(unit.value);
    return big !== unit.value ? big : '';
}

/** One row of the item's first container (pieces typed next). */
function firstContainerRow(): ContainerRowDraft[] {
    const first = itemContainers.value[0];
    return first ? [{ container_uuid: first.uuid, pieces: '' }] : [];
}

/** By container: an inner count or a total above what the containers hold (never raised). */
function containersRaised(rows: ContainerRowDraft[], amount: string | number, amountUnit: string): boolean {
    const ing = props.ingredient;
    if (!ing || rows.length === 0) return false;
    if (innerRaised(ing, rows)) return true;
    return amountProblem(amountInStored(amount, amountUnit, ing.unit), rowsCap(ing, rows.filter((r) => r.container_uuid !== ''))) === 'raised';
}

const allocateRaised = computed(() => allocateRows.value.some((r) => containersRaised(r.containers, r.amount, r.amount_unit)));
const transferRaised = computed(() => containersRaised(transferForm.containers, transferForm.amount, transferForm.amount_unit));

/** The size of one of the dialog's unit, in the stored unit. */
const entryFactor = computed(() => unitOptions.value.find((o) => o.value === entryUnit.value)?.factor ?? 1);

/** A branch's share in the stored unit (by container: the total typed, else what the containers hold). */
function allocateStored(r: AllocateRow): number {
    if (r.containers.length > 0) {
        const ing = props.ingredient;
        if (!ing) return 0;
        return amountInStored(r.amount, r.amount_unit, ing.unit) ?? rowsCap(ing, r.containers.filter((c) => c.container_uuid !== ''));
    }
    return (parseFloat(qty(r.quantity)) || 0) * entryFactor.value;
}

/** "Total entered" — in the stored unit, read the friendly way. */
const allocateTotal = computed(() =>
    allocateRows.value.reduce((s, r) => s + allocateStored(r), 0),
);

const distributeTotal = computed(() =>
    distributeRows.value.reduce((s, r) => s + (parseFloat(qty(r.quantity)) || 0), 0),
);
const distributeRemainder = computed(() =>
    (parseFloat(qty(distributeForm.quantity)) || 0) - distributeTotal.value,
);
// Over-distributed only when it exceeds the total by more than float noise —
// the same 1e-9 epsilon as doDistribute() and the server guard, so an exact
// split (e.g. 0.1+0.1+0.1 vs 0.3) isn't wrongly blocked.
const distributeOver = computed(() => distributeRemainder.value < -1e-9);

function round3(n: number): number {
    // Quantities are decimal:3 — round for display so float accumulation
    // (0.30000000000000004) doesn't surface in the UI.
    return Math.round(n * 1000) / 1000;
}

function resetForms(): void {
    distributeForm.quantity = '';
    distributeForm.note = '';
    distributeRows.value = branches.value.map((b) => ({
        branch_uuid: b.branch_uuid,
        branch_name: b.branch_name,
        quantity: '',
    }));
    receiveForm.quantity = '';
    receiveForm.note = '';
    receiveCost.value = blankCost();
    distributeCost.value = blankCost();
    allocateRows.value = branches.value.map((b) => ({
        branch_uuid: b.branch_uuid,
        branch_name: b.branch_name,
        quantity: '',
        containers: [],
        amount: '',
        amount_unit: containerAmountUnit(),
    }));
    allocateNote.value = '';
    transferForm.from_branch_uuid = branches.value[0]?.branch_uuid ?? '';
    transferForm.to_branch_uuid = branches.value[1]?.branch_uuid ?? '';
    transferForm.quantity = '';
    transferForm.note = '';
    transferForm.containers = [];
    transferForm.amount = '';
    transferForm.amount_unit = containerAmountUnit();
    adjustForm.branch_uuid = '';
    adjustForm.signed_quantity = '';
    adjustForm.note = '';
    resetCorrectRows();
}

function actionLabel(a: Action): string {
    if (a === 'containers') return t('containers.correct.action');
    return a === 'distribute' ? 'Receive & Distribute' : a;
}

async function load(): Promise<void> {
    if (!props.ingredientUuid) return;
    const uuid = props.ingredientUuid;
    loading.value = true;
    error.value = null;
    actionError.value = null;
    actionOk.value = null;
    try {
        const res = await getIngredientStock(uuid);
        // The dialog may have been closed and reopened for ANOTHER
        // ingredient while this request was in flight — discard then.
        if (props.ingredientUuid !== uuid) return;
        summary.value = res.data;
        // PT — load the active tax rates once (best-effort; the tax control just
        // stays hidden if this fails).
        if (taxes.value.length === 0) {
            try {
                taxes.value = (await listTaxes()).data.filter((x) => x.is_active);
            } catch { /* tax control optional */ }
        }
        resetForms();
        entryUnit.value = defaultEntryUnit();
    } catch (e) {
        if (props.ingredientUuid !== uuid) return;
        error.value = e instanceof ApiError ? e.message : 'Could not load stock.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.ingredientUuid],
    () => {
        if (props.open && props.ingredientUuid) {
            // Restricted users have no central tabs — open on the first
            // one they CAN use (transfer).
            action.value = availableActions.value[0];
            void load();
        }
    },
    { immediate: true },
);

async function run(fn: () => Promise<{ data: IngredientStockSummary }>, okMsg: string): Promise<void> {
    if (!props.canManage) return;
    const uuid = props.ingredientUuid;
    busy.value = true;
    actionError.value = null;
    actionOk.value = null;
    try {
        const res = await fn();
        // Discard a late response when the dialog has moved on to a
        // different ingredient (close + reopen mid-request) — otherwise
        // ingredient A's balances would render under B's header.
        if (props.ingredientUuid !== uuid) return;
        summary.value = res.data;
        resetForms();
        actionOk.value = okMsg;
    } catch (e) {
        if (props.ingredientUuid !== uuid) return;
        actionError.value = e instanceof ApiError ? e.message : 'Action failed.';
    } finally {
        busy.value = false;
    }
}

function doDistribute(): void {
    if (!props.ingredientUuid || qty(distributeForm.quantity) === '') return;
    const total = parseFloat(qty(distributeForm.quantity)) || 0;
    const lines = distributeRows.value
        .filter((r) => qty(r.quantity) !== '' && (parseFloat(qty(r.quantity)) || 0) > 0)
        .map((r) => ({ branch_uuid: r.branch_uuid, quantity: qty(r.quantity) }));
    const distributed = lines.reduce((s, l) => s + (parseFloat(String(l.quantity)) || 0), 0);
    if (distributed > total + 1e-9) {
        actionError.value = 'You are distributing more than the received total.';
        return;
    }
    void run(
        () => receiveAndDistributeIngredientStock(props.ingredientUuid as string, {
            quantity: qty(distributeForm.quantity),
            allocations: lines,
            unit: wireUnit(),
            note: distributeForm.note || null,
            ...costPayload(distributeCost.value),
        }),
        'Received and distributed to branches.',
    );
}

/** PD5 — the cash-model cost fields → wire payload. */
function costPayload(c: PurchaseCostModel): { total_cost: string | null; delivery_cost: string | null; no_cost: boolean; tax_amount: string | number | null; tax_rate: string | number | null } {
    return {
        total_cost: c.no_cost ? null : (qty(c.total_cost) || null),
        delivery_cost: c.no_cost ? null : (qty(c.delivery_cost) || null),
        no_cost: c.no_cost,
        // PT — tax paid on the item cost (cleared when "No cost").
        tax_amount: c.no_cost ? null : (Number(c.tax_amount) > 0 ? c.tax_amount : null),
        tax_rate: c.no_cost ? null : (c.tax_rate ?? null),
    };
}

function doReceive(): void {
    if (!props.ingredientUuid || qty(receiveForm.quantity) === '') return;
    void run(
        () => receiveIngredientStock(props.ingredientUuid as string, {
            quantity: qty(receiveForm.quantity),
            unit: wireUnit(),
            note: receiveForm.note || null,
            ...costPayload(receiveCost.value),
        }),
        'Received into the warehouse.',
    );
}

function doAllocate(): void {
    if (!props.ingredientUuid) return;
    if (allocateRaised.value) {
        actionError.value = t('containers.total_raised_summary');
        return;
    }
    // Fix order B-2 — a branch by container sends its rows and (when typed) its lowered total.
    const lines: IngredientAllocateLine[] = [];
    for (const r of allocateRows.value) {
        if (r.containers.length > 0) {
            const containers = containerRowsPayload(r.containers);
            if (containers.length === 0) continue;
            const total = qty(r.amount);
            lines.push({ branch_uuid: r.branch_uuid, containers, ...(total !== '' ? { quantity: total, amount_unit: r.amount_unit || null } : {}) });
        } else if (qty(r.quantity) !== '' && (parseFloat(qty(r.quantity)) || 0) > 0) {
            lines.push({ branch_uuid: r.branch_uuid, quantity: qty(r.quantity) });
        }
    }
    if (lines.length === 0) {
        actionError.value = 'Enter a quantity for at least one branch.';
        return;
    }
    void run(
        () => allocateIngredientStock(props.ingredientUuid as string, { allocations: lines, unit: wireUnit(), note: allocateNote.value || null }),
        'Allocated to branches.',
    );
}

function doTransfer(): void {
    if (!props.ingredientUuid) return;
    // Fix order B-2 — by container: the rows and (when typed) the lowered total.
    const containers = containerRowsPayload(transferForm.containers);
    const byContainer = transferForm.containers.length > 0;
    if (byContainer ? containers.length === 0 : qty(transferForm.quantity) === '') return;
    if (transferForm.from_branch_uuid === transferForm.to_branch_uuid) {
        actionError.value = 'Choose two different branches.';
        return;
    }
    if (transferRaised.value) {
        actionError.value = t('containers.total_raised_summary');
        return;
    }
    const total = qty(transferForm.amount);
    void run(
        () => transferIngredientStock(props.ingredientUuid as string, {
            from_branch_uuid: transferForm.from_branch_uuid,
            to_branch_uuid: transferForm.to_branch_uuid,
            ...(byContainer
                ? { containers, ...(total !== '' ? { quantity: total, amount_unit: transferForm.amount_unit || null } : {}) }
                : { quantity: qty(transferForm.quantity), unit: wireUnit() }),
            note: transferForm.note || null,
        }),
        'Transferred between branches.',
    );
}

function doAdjust(): void {
    if (!props.ingredientUuid || qty(adjustForm.signed_quantity) === '' || adjustForm.note.trim() === '') return;
    void run(
        () => adjustIngredientStock(props.ingredientUuid as string, {
            branch_uuid: adjustForm.branch_uuid || null,
            signed_quantity: qty(adjustForm.signed_quantity),
            unit: wireUnit(),
            note: adjustForm.note,
        }),
        'Adjusted.',
    );
}

function fmtType(t: string): string {
    return t.replace(/_/g, ' ');
}
</script>

<template>
    <BaseModal v-if="open" size="3xl" @close="emit('close')">
        <template #header>
            <div>
                <h2 class="text-base font-bold text-slate-900">Warehouse — {{ ingredientName }}</h2>
                <p class="text-xs text-slate-500">Buy centrally, hold a warehouse total, then distribute to branches.</p>
            </div>
        </template>

        <div class="space-y-5">
                <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ error }}</p>
                <p v-else-if="loading" class="text-sm text-slate-500">Loading…</p>

                <template v-if="summary && !loading">
                    <!-- G3 — Show in: Auto / kg·l / g·ml (remembered per browser). -->
                    <div class="flex justify-end">
                        <AmountDisplaySwitch />
                    </div>
                    <!-- Central warehouse + branch balances -->
                    <div class="grid gap-4 sm:grid-cols-[200px_1fr]">
                        <div class="rounded-xl border border-teal-200 bg-teal-50 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-teal-700">Warehouse</p>
                            <p class="mt-1 text-2xl font-black tabular-nums text-teal-900">
                                <span data-test="warehouse-central">{{ displayAmount(summary.central_quantity, unit, amountDisplay).amount }}</span>
                                <span class="text-sm font-semibold text-teal-700">{{ displayAmount(summary.central_quantity, unit, amountDisplay).unit }}</span>
                                <!-- G2 — the same amount in every unit the item knows. -->
                                <button
                                    v-if="hasConversions(conversionSource)"
                                    type="button"
                                    class="ms-1 inline-grid size-6 place-items-center rounded text-teal-600 align-middle transition hover:bg-teal-100"
                                    :title="t('item_kind.conversions')"
                                    :aria-label="t('item_kind.conversions')"
                                    :aria-expanded="conversionsOpen === 'central'"
                                    data-test="warehouse-conversions-button"
                                    @click="toggleConversions('central')"
                                >
                                    <ArrowLeftRight class="size-3.5" />
                                </button>
                            </p>
                            <bdi v-if="conversionsOpen === 'central'" dir="ltr" class="mt-1 block text-[11px] text-teal-800" data-test="warehouse-conversions">{{ conversionsOf(summary.central_quantity, conversionSource, locale).join(' = ') }}</bdi>
                            <!-- B — the total, and what it is in. -->
                            <StockBreakdown :rows="summary.central_breakdown" :counted-at="summary.central_containers_counted_at" tone="teal" class="mt-1" />
                        </div>
                        <div class="rounded-xl border border-slate-200">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-500">
                                        <th class="px-3 py-2 font-semibold">Branch</th>
                                        <th class="px-3 py-2 text-right font-semibold">Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branches" :key="b.branch_uuid" class="border-b border-slate-50 last:border-0">
                                        <td class="px-3 py-2 text-slate-700">{{ b.branch_name }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900">
                                            {{ amount(b.quantity) }}
                                            <button
                                                v-if="b.quantity !== null && hasConversions(conversionSource)"
                                                type="button"
                                                class="ms-1 inline-grid size-5 place-items-center rounded text-slate-400 align-middle transition hover:bg-slate-100 hover:text-slate-700"
                                                :title="t('item_kind.conversions')"
                                                :aria-label="t('item_kind.conversions')"
                                                :aria-expanded="conversionsOpen === b.branch_uuid"
                                                data-test="warehouse-conversions-button"
                                                @click="toggleConversions(b.branch_uuid)"
                                            >
                                                <ArrowLeftRight class="size-3" />
                                            </button>
                                            <bdi v-if="conversionsOpen === b.branch_uuid" dir="ltr" class="block text-[11px] font-normal text-slate-600">{{ conversionsOf(b.quantity, conversionSource, locale).join(' = ') }}</bdi>
                                            <StockBreakdown :rows="b.breakdown" :counted-at="b.containers_counted_at" class="text-end" />
                                        </td>
                                    </tr>
                                    <tr v-if="branches.length === 0">
                                        <td colspan="2" class="px-3 py-3 text-center text-xs text-slate-400">No branches.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div v-if="canManage" class="rounded-xl border border-slate-200 p-4">
                        <div class="mb-3 flex flex-wrap gap-2">
                            <button
                                v-for="a in shownActions"
                                :key="a"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold capitalize transition"
                                :class="action === a ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                @click="action = a; actionError = null; actionOk = null"
                            >{{ actionLabel(a) }}</button>
                        </div>

                        <!-- F4 — every amount below is typed in this unit (kg / l, a pack size, the container). -->
                        <label v-if="action !== 'containers'" class="mb-3 flex items-center gap-2 text-xs font-semibold text-slate-600">
                            Amounts in
                            <select v-model="entryUnit" data-test="warehouse-unit" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-normal">
                                <option v-for="o in unitOptions" :key="o.value || 'base'" :value="o.value">{{ o.label }}</option>
                            </select>
                        </label>

                        <p v-if="actionError" class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ actionError }}</p>
                        <p v-if="actionOk" class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">{{ actionOk }}</p>

                        <!-- Receive & Distribute -->
                        <form v-if="action === 'distribute'" class="space-y-3" @submit.prevent="doDistribute">
                            <p class="text-xs text-slate-500">Receive a purchase and split it across branches in one step ("100 in: 20 / 20 / 25"). Anything you don't distribute stays in the warehouse.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="text-xs font-semibold text-slate-600">Total received ({{ entryUnitName }})</label>
                                <AmountInput v-model="distributeForm.quantity" :unit="entryUnit" :stored-unit="unit" :containers="itemContainers" step="0.0001" placeholder="e.g. 100" input-class="w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums" />
                            </div>
                            <div class="space-y-2">
                                <div v-for="row in distributeRows" :key="row.branch_uuid" class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-slate-700">{{ row.branch_name }}</span>
                                    <input v-model="row.quantity" type="number" step="0.0001" min="0" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums">
                                </div>
                                <p v-if="branches.length === 0" class="text-xs text-slate-400">No branches yet — the whole amount goes to the warehouse.</p>
                            </div>
                            <p class="text-xs text-slate-500">
                                Distributing <span class="font-semibold tabular-nums">{{ round3(distributeTotal) }}</span> of {{ round3(parseFloat(qty(distributeForm.quantity)) || 0) }} —
                                <span class="font-semibold tabular-nums" :class="distributeOver ? 'text-rose-600' : 'text-slate-700'">{{ round3(distributeRemainder) }}</span> stays in the warehouse
                            </p>
                            <input v-model="distributeForm.note" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <PurchaseCostFields v-model="distributeCost" :taxes="taxes" />
                            <button type="submit" :disabled="busy || distributeOver" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Receive &amp; distribute</button>
                        </form>

                        <!-- Receive -->
                        <form v-else-if="action === 'receive'" class="space-y-3" @submit.prevent="doReceive">
                            <p class="text-xs text-slate-500">Add a purchase to the central warehouse ({{ entryUnitName }}).</p>
                            <div class="flex flex-wrap gap-3">
                                <AmountInput v-model="receiveForm.quantity" :unit="entryUnit" :stored-unit="unit" :containers="itemContainers" step="0.0001" placeholder="Quantity" input-class="w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums" />
                                <input v-model="receiveForm.note" type="text" placeholder="Note (optional)" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            </div>
                            <PurchaseCostFields v-model="receiveCost" :taxes="taxes" />
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add to warehouse</button>
                        </form>

                        <!-- Allocate -->
                        <form v-else-if="action === 'allocate'" class="space-y-3" @submit.prevent="doAllocate">
                            <p class="text-xs text-slate-500">Distribute the warehouse ({{ amount(summary.central_quantity) }}) across branches. Total entered: <span class="font-semibold" data-test="allocate-total">{{ friendly(allocateTotal, unit) }}</span></p>
                            <div class="space-y-2">
                                <div v-for="row in allocateRows" :key="row.branch_uuid" class="flex flex-wrap items-start gap-3" data-test="allocate-row">
                                    <span class="flex-1 pt-1.5 text-sm text-slate-700">{{ row.branch_name }}</span>
                                    <div class="flex flex-col items-end gap-1">
                                        <AmountInput v-if="row.containers.length === 0" v-model="row.quantity" :unit="entryUnit" :stored-unit="unit" :containers="itemContainers" step="0.0001" placeholder="0" input-class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums" data-test="allocate-amount" />
                                        <!-- Fix order B-2 — by container, like the branch transfer: rows + a total that may be lowered. -->
                                        <ContainerRows
                                            v-else-if="ingredient"
                                            v-model:rows="row.containers"
                                            v-model:amount="row.amount"
                                            v-model:amount-unit="row.amount_unit"
                                            :ingredient="ingredient"
                                            data-test="allocate-containers"
                                        />
                                        <button v-if="row.containers.length === 0 && itemContainers.length > 0" type="button" class="text-xs font-semibold text-teal-700 hover:underline" data-test="allocate-by-container" @click="row.containers = firstContainerRow(); row.quantity = ''">{{ t('containers.by_container') }}</button>
                                        <button v-else-if="row.containers.length > 0" type="button" class="text-xs font-semibold text-slate-500 hover:underline" @click="row.containers = []; row.amount = ''">{{ t('containers.by_amount') }}</button>
                                    </div>
                                </div>
                            </div>
                            <input v-model="allocateNote" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy || allocateRaised" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Allocate</button>
                        </form>

                        <!-- Transfer -->
                        <form v-else-if="action === 'transfer'" class="space-y-3" @submit.prevent="doTransfer">
                            <p class="text-xs text-slate-500">Move stock from one branch to another (recorded as a branch transfer).</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="transferForm.from_branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <span class="text-slate-400">→</span>
                                <select v-model="transferForm.to_branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <AmountInput v-if="transferForm.containers.length === 0" v-model="transferForm.quantity" :unit="entryUnit" :stored-unit="unit" :containers="itemContainers" step="0.0001" placeholder="Qty" input-class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums" />
                            </div>
                            <!-- Fix order B-2 — by container, like the branch transfer: rows + a total that may be lowered. -->
                            <ContainerRows
                                v-if="transferForm.containers.length > 0 && ingredient"
                                v-model:rows="transferForm.containers"
                                v-model:amount="transferForm.amount"
                                v-model:amount-unit="transferForm.amount_unit"
                                :ingredient="ingredient"
                                data-test="transfer-containers"
                            />
                            <button v-if="transferForm.containers.length === 0 && itemContainers.length > 0" type="button" class="text-xs font-semibold text-teal-700 hover:underline" data-test="warehouse-transfer-by-container" @click="transferForm.containers = firstContainerRow(); transferForm.quantity = ''">{{ t('containers.by_container') }}</button>
                            <button v-else-if="transferForm.containers.length > 0" type="button" class="text-xs font-semibold text-slate-500 hover:underline" @click="transferForm.containers = []; transferForm.amount = ''">{{ t('containers.by_amount') }}</button>
                            <input v-model="transferForm.note" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy || transferRaised" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Transfer</button>
                        </form>

                        <!-- B (tester call 8) — Correct containers: what is on the warehouse shelf; the total does not move. -->
                        <form v-else-if="action === 'containers'" class="space-y-3" data-test="correct-containers" @submit.prevent="doCorrectContainers">
                            <p class="text-xs text-slate-500">{{ t('containers.correct.hint') }}</p>
                            <div class="space-y-2">
                                <div v-for="row in correctRows" :key="row.container_uuid" class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-slate-700">{{ row.label }}</span>
                                    <input v-model="row.pieces" type="number" step="any" min="0" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums" data-test="correct-pieces">
                                </div>
                            </div>
                            <p class="text-xs text-slate-500" data-test="correct-holds">{{ t('containers.correct.holds', { holds: friendly(correctHolds, unit), total: amount(summary.central_quantity) }) }}</p>
                            <input v-model="correctNote" type="text" :placeholder="t('containers.correct.note')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ t('containers.correct.submit') }}</button>
                        </form>

                        <!-- Adjust -->
                        <form v-else class="space-y-3" @submit.prevent="doAdjust">
                            <p class="text-xs text-slate-500">Correct a balance (signed: e.g. -3 for spillage). A note is required.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="adjustForm.branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-if="!isBranchRestricted" value="">Warehouse</option>
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <AmountInput v-model="adjustForm.signed_quantity" :unit="entryUnit" :stored-unit="unit" :containers="itemContainers" step="0.0001" min="" placeholder="±Qty" input-class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums" />
                            </div>
                            <input v-model="adjustForm.note" type="text" placeholder="Reason (required)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Adjust</button>
                        </form>
                    </div>

                    <!-- History -->
                    <div>
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Recent movements</p>
                        <div class="rounded-xl border border-slate-200">
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="m in summary.recent_movements" :key="m.id" class="border-b border-slate-50 last:border-0">
                                        <td class="px-3 py-2 capitalize text-slate-700">{{ fmtType(m.movement_type) }}</td>
                                        <td class="px-3 py-2 text-slate-500">{{ m.branch_name ?? 'Warehouse' }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="m.quantity.startsWith('-') ? 'text-rose-600' : 'text-emerald-600'">{{ amount(m.quantity) }}</td>
                                        <td class="px-3 py-2 text-xs text-slate-400">{{ m.note }}</td>
                                    </tr>
                                    <tr v-if="summary.recent_movements.length === 0">
                                        <td colspan="4" class="px-3 py-3 text-center text-xs text-slate-400">No movements yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
        </div>
    </BaseModal>
</template>

<script setup lang="ts">
/**
 * Phase 7 — central pool + per-branch distribution for a UNIT product.
 * Receive into the central pool, allocate it out to branches, transfer between
 * branches, adjust a count, and read the movement history. All quantities are
 * decimal strings (never parsed for precision-critical math here).
 */
import { computed, reactive, ref, watch } from 'vue';
import BaseModal from '@/Components/BaseModal.vue';
import PurchaseCostFields, { type PurchaseCostModel } from '@/Pages/Merchant/Inventory/PurchaseCostFields.vue';
import { ApiError } from '@/lib/api';
import { authState } from '@/stores/auth';
import {
    adjustProductStock,
    allocateProductStock,
    getProductStock,
    receiveAndDistributeProductStock,
    receiveProductStock,
    recordProductWaste,
    transferProductStock,
    type ProductStockSummary,
} from '@/lib/api/productStock';
import { listTaxes, type Tax } from '@/lib/api/taxes';

const props = defineProps<{
    open: boolean;
    productUuid: string | null;
    productName: string;
    canManage: boolean;
    /** PD2 — the product's cost price, for the qty x cost suggestion. */
    costPrice?: string | null;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

type Action = 'distribute' | 'receive' | 'allocate' | 'transfer' | 'adjust' | 'waste';

const wasteReasons = ['expired', 'spoiled', 'broken', 'dropped', 'contamination', 'other'] as const;

const loading = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionOk = ref<string | null>(null);
const summary = ref<ProductStockSummary | null>(null);
const action = ref<Action>('distribute');

// P-G5 — a branch-restricted user (branch_scope is a list) can't touch
// the central pool: the server 403s receive/distribute/allocate + central
// adjust. Hide those tabs so the dialog only offers what works.
const isBranchRestricted = computed(() => Array.isArray(authState.user?.branch_scope));
const availableActions = computed<Action[]>(() =>
    isBranchRestricted.value
        ? ['transfer', 'adjust', 'waste']
        : ['distribute', 'receive', 'allocate', 'transfer', 'adjust', 'waste'],
);

// Quantity fields are bound to type="number" inputs: Vue's v-model stores a
// NUMBER once a value is typed ('' only while blank) — hence string | number,
// and qty() below normalizes before every trim/parseFloat/wire payload.
const distributeForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
const distributeRows = ref<{ branch_uuid: string; branch_name: string; quantity: string | number }[]>([]);
const receiveForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
// PD5 — the cash-model purchase cost (item + delivery + "no cost") for the buys.
function blankCost(): PurchaseCostModel { return { total_cost: '', delivery_cost: '', no_cost: false, tax_amount: 0, tax_rate: null }; }
const receiveCost = ref<PurchaseCostModel>(blankCost());
const distributeCost = ref<PurchaseCostModel>(blankCost());
// PT — active company taxes offered as purchase-tax rates (loaded once).
const taxes = ref<Tax[]>([]);
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
const allocateRows = ref<{ branch_uuid: string; branch_name: string; quantity: string | number }[]>([]);
const allocateNote = ref('');
const transferForm = reactive<{ from_branch_uuid: string; to_branch_uuid: string; quantity: string | number; note: string }>(
    { from_branch_uuid: '', to_branch_uuid: '', quantity: '', note: '' },
);
const adjustForm = reactive<{ branch_uuid: string; signed_quantity: string | number; note: string }>(
    { branch_uuid: '', signed_quantity: '', note: '' },
);
const wasteForm = reactive<{ branch_uuid: string; quantity: string | number; reason: string; notes: string }>(
    { branch_uuid: '', quantity: '', reason: 'expired', notes: '' },
);

function qty(v: string | number): string {
    return String(v ?? '').trim();
}

// PD2 — placeholder suggestion for the purchase cost: quantity x the
// product's cost price. A suggestion only — the merchant types what was
// actually paid.
function costSuggestion(quantity: string | number): string {
    const q = parseFloat(qty(quantity));
    const c = parseFloat(props.costPrice ?? '');
    if (!isFinite(q) || q <= 0 || !isFinite(c) || c <= 0) return 'e.g. 12.500';
    return (Math.round(q * c * 1000) / 1000).toFixed(3);
}

const branches = computed(() => summary.value?.branches ?? []);

const allocateTotal = computed(() =>
    allocateRows.value.reduce((s, r) => s + (parseFloat(qty(r.quantity)) || 0), 0),
);

const distributeTotal = computed(() =>
    distributeRows.value.reduce((s, r) => s + (parseFloat(qty(r.quantity)) || 0), 0),
);
const distributeRemainder = computed(() =>
    (parseFloat(qty(distributeForm.quantity)) || 0) - distributeTotal.value,
);
// Over-distributed only when it exceeds the total by more than float noise — use
// the same 1e-9 epsilon as doDistribute() and the server guard, so an exact split
// (e.g. 0.1+0.1+0.1 vs 0.3) isn't wrongly blocked.
const distributeOver = computed(() => distributeRemainder.value < -1e-9);

function round3(n: number): number {
    // Quantities are decimal:3 — round for display so float accumulation
    // (0.30000000000000004) doesn't surface in the UI.
    return Math.round(n * 1000) / 1000;
}

function resetForms(): void {
    distributeForm.quantity = '';
    distributeForm.note = '';
    distributeCost.value = blankCost();
    distributeRows.value = branches.value.map((b) => ({
        branch_uuid: b.branch_uuid,
        branch_name: b.branch_name,
        quantity: '',
    }));
    receiveForm.quantity = '';
    receiveForm.note = '';
    receiveCost.value = blankCost();
    allocateRows.value = branches.value.map((b) => ({
        branch_uuid: b.branch_uuid,
        branch_name: b.branch_name,
        quantity: '',
    }));
    allocateNote.value = '';
    transferForm.from_branch_uuid = branches.value[0]?.branch_uuid ?? '';
    transferForm.to_branch_uuid = branches.value[1]?.branch_uuid ?? '';
    transferForm.quantity = '';
    transferForm.note = '';
    adjustForm.branch_uuid = '';
    adjustForm.signed_quantity = '';
    adjustForm.note = '';
    wasteForm.branch_uuid = branches.value[0]?.branch_uuid ?? '';
    wasteForm.quantity = '';
    wasteForm.reason = 'expired';
    wasteForm.notes = '';
}

function actionLabel(a: Action): string {
    return a === 'distribute' ? 'Receive & Distribute' : a;
}

async function load(): Promise<void> {
    if (!props.productUuid) return;
    const uuid = props.productUuid;
    loading.value = true;
    error.value = null;
    actionError.value = null;
    actionOk.value = null;
    try {
        const res = await getProductStock(uuid);
        // The dialog may have been closed and reopened for ANOTHER product
        // while this request was in flight — discard then.
        if (props.productUuid !== uuid) return;
        summary.value = res.data;
        // PT — load the active tax rates once (best-effort; the tax control just
        // stays hidden if this fails).
        if (taxes.value.length === 0) {
            try {
                taxes.value = (await listTaxes()).data.filter((x) => x.is_active);
            } catch { /* tax control optional */ }
        }
        resetForms();
    } catch (e) {
        if (props.productUuid !== uuid) return;
        error.value = e instanceof ApiError ? e.message : 'Could not load stock.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, props.productUuid],
    () => {
        if (props.open && props.productUuid) {
            action.value = availableActions.value[0];
            void load();
        }
    },
    { immediate: true },
);

async function run(fn: () => Promise<{ data: ProductStockSummary }>, okMsg: string): Promise<void> {
    if (!props.canManage) return;
    const uuid = props.productUuid;
    busy.value = true;
    actionError.value = null;
    actionOk.value = null;
    try {
        const res = await fn();
        // Discard a late response when the dialog has moved on to a
        // different product (close + reopen mid-request) — otherwise
        // product A's balances would render under B's header.
        if (props.productUuid !== uuid) return;
        summary.value = res.data;
        resetForms();
        actionOk.value = okMsg;
    } catch (e) {
        if (props.productUuid !== uuid) return;
        actionError.value = e instanceof ApiError ? e.message : 'Action failed.';
    } finally {
        busy.value = false;
    }
}

function doDistribute(): void {
    if (!props.productUuid || qty(distributeForm.quantity) === '') return;
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
        () => receiveAndDistributeProductStock(props.productUuid as string, {
            quantity: qty(distributeForm.quantity),
            allocations: lines,
            note: distributeForm.note || null,
            ...costPayload(distributeCost.value),
        }),
        'Received and distributed to branches.',
    );
}

function doReceive(): void {
    if (!props.productUuid || qty(receiveForm.quantity) === '') return;
    void run(
        () => receiveProductStock(props.productUuid as string, {
            quantity: qty(receiveForm.quantity),
            note: receiveForm.note || null,
            ...costPayload(receiveCost.value),
        }),
        'Received into the central pool.',
    );
}

function doAllocate(): void {
    if (!props.productUuid) return;
    const lines = allocateRows.value
        .filter((r) => qty(r.quantity) !== '' && (parseFloat(qty(r.quantity)) || 0) > 0)
        .map((r) => ({ branch_uuid: r.branch_uuid, quantity: qty(r.quantity) }));
    if (lines.length === 0) {
        actionError.value = 'Enter a quantity for at least one branch.';
        return;
    }
    void run(
        () => allocateProductStock(props.productUuid as string, { allocations: lines, note: allocateNote.value || null }),
        'Allocated to branches.',
    );
}

function doTransfer(): void {
    if (!props.productUuid || qty(transferForm.quantity) === '') return;
    if (transferForm.from_branch_uuid === transferForm.to_branch_uuid) {
        actionError.value = 'Choose two different branches.';
        return;
    }
    void run(
        () => transferProductStock(props.productUuid as string, {
            from_branch_uuid: transferForm.from_branch_uuid,
            to_branch_uuid: transferForm.to_branch_uuid,
            quantity: qty(transferForm.quantity),
            note: transferForm.note || null,
        }),
        'Transferred between branches.',
    );
}

function doAdjust(): void {
    if (!props.productUuid || qty(adjustForm.signed_quantity) === '' || adjustForm.note.trim() === '') return;
    void run(
        () => adjustProductStock(props.productUuid as string, {
            branch_uuid: adjustForm.branch_uuid || null,
            signed_quantity: qty(adjustForm.signed_quantity),
            note: adjustForm.note,
        }),
        'Adjusted.',
    );
}

// The branch shelf balance for the product the waste form points at.
const wasteBranchBalance = computed(() =>
    branches.value.find((b) => b.branch_uuid === wasteForm.branch_uuid)?.stock_qty ?? '0.000',
);
const wasteCostPreview = computed(() => {
    const q = parseFloat(qty(wasteForm.quantity));
    const c = parseFloat(props.costPrice ?? '');
    if (!isFinite(q) || q <= 0 || !isFinite(c) || c <= 0) return null;
    return (Math.round(q * c * 1000) / 1000).toFixed(3);
});

function doWaste(): void {
    if (!props.productUuid || !wasteForm.branch_uuid || qty(wasteForm.quantity) === '') return;
    void run(
        () => recordProductWaste(props.productUuid as string, {
            branch_uuid: wasteForm.branch_uuid,
            quantity: qty(wasteForm.quantity),
            reason: wasteForm.reason,
            notes: wasteForm.notes || null,
        }),
        'Waste recorded.',
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
                <h2 class="text-base font-bold text-slate-900">Stock — {{ productName }}</h2>
                <p class="text-xs text-slate-500">Hold a central total, then distribute units to branches.</p>
            </div>
        </template>

        <div class="space-y-5">
                <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ error }}</p>
                <p v-else-if="loading" class="text-sm text-slate-500">Loading…</p>

                <template v-if="summary && !loading">
                    <!-- Central pool + branch balances -->
                    <div class="grid gap-4 sm:grid-cols-[200px_1fr]">
                        <div class="rounded-xl border border-teal-200 bg-teal-50 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-teal-700">Central pool</p>
                            <p class="mt-1 text-2xl font-black tabular-nums text-teal-900">{{ summary.central_quantity }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-500">
                                        <th class="px-3 py-2 font-semibold">Branch</th>
                                        <th class="px-3 py-2 text-right font-semibold">Units</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branches" :key="b.branch_uuid" class="border-b border-slate-50 last:border-0">
                                        <td class="px-3 py-2 text-slate-700">{{ b.branch_name }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900">
                                            {{ b.stock_qty ?? '—' }}
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
                                v-for="a in availableActions"
                                :key="a"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold capitalize transition"
                                :class="action === a ? 'bg-teal-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                @click="action = a; actionError = null; actionOk = null"
                            >{{ actionLabel(a) }}</button>
                        </div>

                        <p v-if="actionError" class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ actionError }}</p>
                        <p v-if="actionOk" class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">{{ actionOk }}</p>

                        <!-- Receive & Distribute -->
                        <form v-if="action === 'distribute'" class="space-y-3" @submit.prevent="doDistribute">
                            <p class="text-xs text-slate-500">Receive a bulk quantity and split it across branches in one step. Anything you don't distribute stays in the central pool.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="text-xs font-semibold text-slate-600">Total received</label>
                                <input v-model="distributeForm.quantity" type="number" step="0.001" min="0" placeholder="e.g. 80" class="w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                            </div>
                            <PurchaseCostFields v-model="distributeCost" :cost-placeholder="costSuggestion(distributeForm.quantity)" :taxes="taxes" />
                            <div class="space-y-2">
                                <div v-for="row in distributeRows" :key="row.branch_uuid" class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-slate-700">{{ row.branch_name }}</span>
                                    <input v-model="row.quantity" type="number" step="0.001" min="0" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums">
                                </div>
                                <p v-if="branches.length === 0" class="text-xs text-slate-400">No branches yet — the whole amount goes to the central pool.</p>
                            </div>
                            <p class="text-xs text-slate-500">
                                Distributing <span class="font-semibold tabular-nums">{{ round3(distributeTotal) }}</span> of {{ round3(parseFloat(qty(distributeForm.quantity)) || 0) }} —
                                <span class="font-semibold tabular-nums" :class="distributeOver ? 'text-rose-600' : 'text-slate-700'">{{ round3(distributeRemainder) }}</span> stays in central
                            </p>
                            <input v-model="distributeForm.note" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy || distributeOver" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Receive &amp; distribute</button>
                        </form>

                        <!-- Receive -->
                        <form v-else-if="action === 'receive'" class="space-y-3" @submit.prevent="doReceive">
                            <p class="text-xs text-slate-500">Add finished goods to the central pool.</p>
                            <div class="flex flex-wrap items-end gap-3">
                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-600">Quantity</span>
                                    <input v-model="receiveForm.quantity" type="number" step="0.001" min="0" placeholder="e.g. 80" class="mt-1 w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                                </label>
                                <label class="block min-w-[10rem] flex-1">
                                    <span class="text-xs font-semibold text-slate-600">Note (optional)</span>
                                    <input v-model="receiveForm.note" type="text" placeholder="" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                </label>
                            </div>
                            <PurchaseCostFields v-model="receiveCost" :cost-placeholder="costSuggestion(receiveForm.quantity)" :taxes="taxes" />
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add to central</button>
                        </form>

                        <!-- Allocate -->
                        <form v-else-if="action === 'allocate'" class="space-y-3" @submit.prevent="doAllocate">
                            <p class="text-xs text-slate-500">Distribute the central pool ({{ summary.central_quantity }}) across branches. Total entered: <span class="font-semibold">{{ allocateTotal }}</span></p>
                            <div class="space-y-2">
                                <div v-for="row in allocateRows" :key="row.branch_uuid" class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-slate-700">{{ row.branch_name }}</span>
                                    <input v-model="row.quantity" type="number" step="0.001" min="0" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums">
                                </div>
                            </div>
                            <input v-model="allocateNote" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Allocate</button>
                        </form>

                        <!-- Transfer -->
                        <form v-else-if="action === 'transfer'" class="space-y-3" @submit.prevent="doTransfer">
                            <p class="text-xs text-slate-500">Move units from one branch to another.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="transferForm.from_branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <span class="text-slate-400">→</span>
                                <select v-model="transferForm.to_branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <input v-model="transferForm.quantity" type="number" step="0.001" min="0" placeholder="Qty" class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                            </div>
                            <input v-model="transferForm.note" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Transfer</button>
                        </form>

                        <!-- Waste -->
                        <form v-else-if="action === 'waste'" class="space-y-3" @submit.prevent="doWaste">
                            <p class="text-xs text-slate-500">Record cooked or bought-in stock thrown away at a branch. The cost is tracked as a loss (not an expense — it was already paid for).</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="wasteForm.branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <input v-model="wasteForm.quantity" type="number" step="0.001" min="0" placeholder="Qty wasted" class="w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                                <select v-model="wasteForm.reason" class="rounded-lg border border-slate-200 px-3 py-2 text-sm capitalize">
                                    <option v-for="r in wasteReasons" :key="r" :value="r">{{ r }}</option>
                                </select>
                            </div>
                            <p class="text-xs text-slate-500">
                                On shelf: <span class="font-semibold tabular-nums">{{ wasteBranchBalance }}</span>
                                <template v-if="wasteCostPreview"> · loss ≈ <span class="font-semibold tabular-nums">{{ wasteCostPreview }}</span></template>
                            </p>
                            <input v-model="wasteForm.notes" type="text" :placeholder="wasteForm.reason === 'other' ? 'Note (required for “other”)' : 'Note (optional)'" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <button type="submit" :disabled="busy || branches.length === 0" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Record waste</button>
                        </form>

                        <!-- Adjust -->
                        <form v-else class="space-y-3" @submit.prevent="doAdjust">
                            <p class="text-xs text-slate-500">Correct a count (signed: e.g. -3 for breakage). A note is required.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="adjustForm.branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-if="!isBranchRestricted" value="">Central pool</option>
                                    <option v-for="b in branches" :key="b.branch_uuid" :value="b.branch_uuid">{{ b.branch_name }}</option>
                                </select>
                                <input v-model="adjustForm.signed_quantity" type="number" step="0.001" placeholder="±Qty" class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
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
                                        <td class="px-3 py-2 text-slate-500">{{ m.branch_name ?? 'Central' }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="m.quantity.startsWith('-') ? 'text-rose-600' : 'text-emerald-600'">{{ m.quantity }}</td>
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

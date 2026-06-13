<script setup lang="ts">
/**
 * P-G4 — central warehouse + per-branch distribution for an INGREDIENT, the
 * ingredient twin of Catalogue/ProductStockDialog.vue ("buy 100 kg of sugar
 * once, then split 20/20/25 to branches"). Receive into the warehouse,
 * Receive & Distribute in one step, allocate out to branches, transfer
 * between branches (a real BranchTransfer), adjust a balance, and read the
 * movement history. All quantities are decimal strings in the ingredient's
 * BASE unit (never parsed for precision-critical math here).
 */
import { computed, reactive, ref, watch } from 'vue';
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
    type IngredientStockSummary,
} from '@/lib/api/ingredientStock';

const props = defineProps<{
    open: boolean;
    ingredientUuid: string | null;
    ingredientName: string;
    canManage: boolean;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

type Action = 'distribute' | 'receive' | 'allocate' | 'transfer' | 'adjust';

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
        : ['distribute', 'receive', 'allocate', 'transfer', 'adjust'],
);

// Quantity fields are bound to type="number" inputs: Vue's v-model stores a
// NUMBER once a value is typed ('' only while blank) — hence string | number,
// and qty() below normalizes before every trim/parseFloat/wire payload.
const distributeForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
const distributeRows = ref<{ branch_uuid: string; branch_name: string; quantity: string | number }[]>([]);
const receiveForm = reactive<{ quantity: string | number; note: string }>({ quantity: '', note: '' });
// PD5 — the cash-model purchase cost for the receive / receive-and-distribute
// buys (buying ingredients into the warehouse is a purchase = an expense).
function blankCost(): PurchaseCostModel { return { total_cost: '', delivery_cost: '', no_cost: false }; }
const receiveCost = ref<PurchaseCostModel>(blankCost());
const distributeCost = ref<PurchaseCostModel>(blankCost());
const allocateRows = ref<{ branch_uuid: string; branch_name: string; quantity: string | number }[]>([]);
const allocateNote = ref('');
const transferForm = reactive<{ from_branch_uuid: string; to_branch_uuid: string; quantity: string | number; note: string }>(
    { from_branch_uuid: '', to_branch_uuid: '', quantity: '', note: '' },
);
const adjustForm = reactive<{ branch_uuid: string; signed_quantity: string | number; note: string }>(
    { branch_uuid: '', signed_quantity: '', note: '' },
);

function qty(v: string | number): string {
    return String(v ?? '').trim();
}

const branches = computed(() => summary.value?.branches ?? []);
const unit = computed(() => summary.value?.unit ?? '');

const allocateTotal = computed(() =>
    allocateRows.value.reduce((s, r) => s + (parseFloat(qty(r.quantity)) || 0), 0),
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
    }));
    allocateNote.value = '';
    transferForm.from_branch_uuid = branches.value[0]?.branch_uuid ?? '';
    transferForm.to_branch_uuid = branches.value[1]?.branch_uuid ?? '';
    transferForm.quantity = '';
    transferForm.note = '';
    adjustForm.branch_uuid = '';
    adjustForm.signed_quantity = '';
    adjustForm.note = '';
}

function actionLabel(a: Action): string {
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
        resetForms();
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
            note: distributeForm.note || null,
            ...costPayload(distributeCost.value),
        }),
        'Received and distributed to branches.',
    );
}

/** PD5 — the cash-model cost fields → wire payload. */
function costPayload(c: PurchaseCostModel): { total_cost: string | null; delivery_cost: string | null; no_cost: boolean } {
    return {
        total_cost: c.no_cost ? null : (qty(c.total_cost) || null),
        delivery_cost: c.no_cost ? null : (qty(c.delivery_cost) || null),
        no_cost: c.no_cost,
    };
}

function doReceive(): void {
    if (!props.ingredientUuid || qty(receiveForm.quantity) === '') return;
    void run(
        () => receiveIngredientStock(props.ingredientUuid as string, {
            quantity: qty(receiveForm.quantity),
            note: receiveForm.note || null,
            ...costPayload(receiveCost.value),
        }),
        'Received into the warehouse.',
    );
}

function doAllocate(): void {
    if (!props.ingredientUuid) return;
    const lines = allocateRows.value
        .filter((r) => qty(r.quantity) !== '' && (parseFloat(qty(r.quantity)) || 0) > 0)
        .map((r) => ({ branch_uuid: r.branch_uuid, quantity: qty(r.quantity) }));
    if (lines.length === 0) {
        actionError.value = 'Enter a quantity for at least one branch.';
        return;
    }
    void run(
        () => allocateIngredientStock(props.ingredientUuid as string, { allocations: lines, note: allocateNote.value || null }),
        'Allocated to branches.',
    );
}

function doTransfer(): void {
    if (!props.ingredientUuid || qty(transferForm.quantity) === '') return;
    if (transferForm.from_branch_uuid === transferForm.to_branch_uuid) {
        actionError.value = 'Choose two different branches.';
        return;
    }
    void run(
        () => transferIngredientStock(props.ingredientUuid as string, {
            from_branch_uuid: transferForm.from_branch_uuid,
            to_branch_uuid: transferForm.to_branch_uuid,
            quantity: qty(transferForm.quantity),
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
                    <!-- Central warehouse + branch balances -->
                    <div class="grid gap-4 sm:grid-cols-[200px_1fr]">
                        <div class="rounded-xl border border-teal-200 bg-teal-50 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-teal-700">Warehouse</p>
                            <p class="mt-1 text-2xl font-black tabular-nums text-teal-900">
                                {{ summary.central_quantity }}
                                <span class="text-sm font-semibold text-teal-700">{{ unit }}</span>
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-200">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-500">
                                        <th class="px-3 py-2 font-semibold">Branch</th>
                                        <th class="px-3 py-2 text-right font-semibold">Stock ({{ unit }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branches" :key="b.branch_uuid" class="border-b border-slate-50 last:border-0">
                                        <td class="px-3 py-2 text-slate-700">{{ b.branch_name }}</td>
                                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900">
                                            {{ b.quantity ?? '—' }}
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
                            <p class="text-xs text-slate-500">Receive a purchase and split it across branches in one step ("100 in: 20 / 20 / 25"). Anything you don't distribute stays in the warehouse.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="text-xs font-semibold text-slate-600">Total received ({{ unit }})</label>
                                <input v-model="distributeForm.quantity" type="number" step="0.001" min="0" placeholder="e.g. 100" class="w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                            </div>
                            <div class="space-y-2">
                                <div v-for="row in distributeRows" :key="row.branch_uuid" class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-slate-700">{{ row.branch_name }}</span>
                                    <input v-model="row.quantity" type="number" step="0.001" min="0" placeholder="0" class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-sm tabular-nums">
                                </div>
                                <p v-if="branches.length === 0" class="text-xs text-slate-400">No branches yet — the whole amount goes to the warehouse.</p>
                            </div>
                            <p class="text-xs text-slate-500">
                                Distributing <span class="font-semibold tabular-nums">{{ round3(distributeTotal) }}</span> of {{ round3(parseFloat(qty(distributeForm.quantity)) || 0) }} —
                                <span class="font-semibold tabular-nums" :class="distributeOver ? 'text-rose-600' : 'text-slate-700'">{{ round3(distributeRemainder) }}</span> stays in the warehouse
                            </p>
                            <input v-model="distributeForm.note" type="text" placeholder="Note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <PurchaseCostFields v-model="distributeCost" />
                            <button type="submit" :disabled="busy || distributeOver" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Receive &amp; distribute</button>
                        </form>

                        <!-- Receive -->
                        <form v-else-if="action === 'receive'" class="space-y-3" @submit.prevent="doReceive">
                            <p class="text-xs text-slate-500">Add a purchase to the central warehouse ({{ unit }}).</p>
                            <div class="flex flex-wrap gap-3">
                                <input v-model="receiveForm.quantity" type="number" step="0.001" min="0" placeholder="Quantity" class="w-36 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums">
                                <input v-model="receiveForm.note" type="text" placeholder="Note (optional)" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            </div>
                            <PurchaseCostFields v-model="receiveCost" />
                            <button type="submit" :disabled="busy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add to warehouse</button>
                        </form>

                        <!-- Allocate -->
                        <form v-else-if="action === 'allocate'" class="space-y-3" @submit.prevent="doAllocate">
                            <p class="text-xs text-slate-500">Distribute the warehouse ({{ summary.central_quantity }} {{ unit }}) across branches. Total entered: <span class="font-semibold">{{ round3(allocateTotal) }}</span></p>
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
                            <p class="text-xs text-slate-500">Move stock from one branch to another (recorded as a branch transfer).</p>
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

                        <!-- Adjust -->
                        <form v-else class="space-y-3" @submit.prevent="doAdjust">
                            <p class="text-xs text-slate-500">Correct a balance (signed: e.g. -3 for spillage). A note is required.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <select v-model="adjustForm.branch_uuid" class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                    <option v-if="!isBranchRestricted" value="">Warehouse</option>
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
                                        <td class="px-3 py-2 text-slate-500">{{ m.branch_name ?? 'Warehouse' }}</td>
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

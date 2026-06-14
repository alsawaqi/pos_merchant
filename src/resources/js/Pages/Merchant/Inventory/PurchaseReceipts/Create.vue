<script setup lang="ts">
/**
 * PD6 — the Goods Received Note form (a full page, not a popup).
 *
 * One delivery, recorded in one submit. Add many lines mixing ingredients +
 * bought-in products + physical items; each line gets a quantity + cost and an
 * OPTIONAL inline branch split (whatever is not split stays in the central
 * warehouse, to allocate later). Add any number of named extra charges
 * (delivery, customs…), each booking its own categorized expense. The backend
 * fans every line out to the existing receive/allocate/expense machinery in one
 * atomic transaction.
 *
 * Server gate: inventory.manage + access to all branches (it credits the
 * central warehouse).
 */

import { ClipboardList, Plus, Trash2, ChevronDown, ChevronUp } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import { listIngredients, listSuppliers, type Ingredient, type Supplier } from '@/lib/api/inventory';
import { listProducts, type Product } from '@/lib/api/catalogue';
import { listPhysicalItems, type PhysicalItem } from '@/lib/api/physicalItems';
import { listTaxes, type Tax } from '@/lib/api/taxes';
import { createPurchaseReceipt, type CreatePurchaseReceiptPayload } from '@/lib/api/purchaseReceipts';
import PurchaseTaxField, { type PurchaseTaxModel } from '@/Pages/Merchant/Inventory/PurchaseTaxField.vue';

const { t, locale } = useI18n();
const router = useRouter();
const isAr = computed(() => locale.value === 'ar');

// ---- reference data ------------------------------------------------
const suppliers = ref<Supplier[]>([]);
const ingredients = ref<Ingredient[]>([]);
const products = ref<Product[]>([]);
const physicalItems = ref<PhysicalItem[]>([]);
const branches = ref<Branch[]>([]);
const taxes = ref<Tax[]>([]);
const refDataError = ref<string | null>(null);

// Charge categories the form offers (the server accepts the full enum; these
// are the ones that make sense on a purchase receipt). 'delivery' is default.
const chargeCategories = ['delivery', 'supplies', 'utilities', 'maintenance', 'other'] as const;

// ---- form state ----------------------------------------------------
interface AllocationRow { branch_uuid: string; quantity: string | number; }
interface LineRow {
    id: number; // stable v-for key — see nextRowId()
    itemKey: string; // "ingredient:uuid" | "product:uuid" | "physical:uuid"
    quantity: string | number;
    line_cost: string | number;
    tax: PurchaseTaxModel; // PT — optional tax paid on this line
    showAllocations: boolean;
    allocations: AllocationRow[];
}
interface ChargeRow { id: number; name: string; category: string; amount: string | number; tax: PurchaseTaxModel; }

const header = reactive({
    supplier_uuid: '',
    reference: '',
    received_at: new Date().toISOString().slice(0, 10),
    note: '',
});

const lines = ref<LineRow[]>([]);
const charges = ref<ChargeRow[]>([]);

const submitting = ref(false);
const submitError = ref<string | null>(null);

// Monotonic row id so each line/charge keeps a STABLE v-for key across a
// mid-list removal. Index keys would let Vue patch the wrong (stateful)
// PurchaseTaxField instance in place when a row above it is spliced out, which
// could rebind a stale tax choice onto the shifted row.
let rowSeq = 0;
function nextRowId(): number {
    return (rowSeq += 1);
}

function blankAllocations(): AllocationRow[] {
    return branches.value.map((b) => ({ branch_uuid: b.uuid, quantity: '' }));
}

function addLine(): void {
    lines.value.push({ id: nextRowId(), itemKey: '', quantity: '', line_cost: '', tax: { tax_amount: 0, tax_rate: null }, showAllocations: false, allocations: blankAllocations() });
}

function removeLine(idx: number): void {
    lines.value.splice(idx, 1);
}

function addCharge(): void {
    charges.value.push({ id: nextRowId(), name: '', category: 'delivery', amount: '', tax: { tax_amount: 0, tax_rate: null } });
}

function removeCharge(idx: number): void {
    charges.value.splice(idx, 1);
}

// ---- selected-item helpers -----------------------------------------
function itemName(i: { name: string; name_ar?: string | null }): string {
    return (isAr.value ? i.name_ar : null) ?? i.name;
}

/** Resolve a line's chosen item to its display name + measure unit. */
function lineUnit(line: LineRow): string {
    if (!line.itemKey) {
        return '';
    }
    const [kind, uuid] = line.itemKey.split(':');
    if (kind === 'ingredient') {
        const ing = ingredients.value.find((i) => i.uuid === uuid);
        return ing ? String(ing.unit) : '';
    }
    return t('purchase_receipts.form.unit_each');
}

function branchName(uuid: string): string {
    const b = branches.value.find((x) => x.uuid === uuid);
    return b ? itemName(b) : uuid;
}

// ---- live totals + per-line distribution --------------------------
function lineDistributed(line: LineRow): number {
    return line.allocations.reduce((sum, a) => sum + (Number(a.quantity) || 0), 0);
}

function lineRemainder(line: LineRow): number {
    return (Number(line.quantity) || 0) - lineDistributed(line);
}

function lineOverDistributed(line: LineRow): boolean {
    return lineRemainder(line) < -1e-9;
}

/** A line the user STARTED (picked an item) but left without a quantity. */
function lineIncomplete(line: LineRow): boolean {
    return line.itemKey !== '' && !(Number(line.quantity) > 0);
}

const itemsTotal = computed(() => lines.value.reduce((sum, l) => sum + (Number(l.line_cost) || 0), 0));
const chargesTotal = computed(() => charges.value.reduce((sum, c) => sum + (Number(c.amount) || 0), 0));
// PT — Σ of every line + charge tax; grand = items + charges + tax (gross).
// Mirror the backend's rule (tax only counts when the base cost is positive — a
// free line / zero charge books no tax) so this preview matches the persisted
// receipt totals exactly.
const taxTotal = computed(() =>
    lines.value.reduce((s, l) => s + (Number(l.line_cost) > 0 ? (Number(l.tax.tax_amount) || 0) : 0), 0)
    + charges.value.reduce((s, c) => s + (Number(c.amount) > 0 ? (Number(c.tax.tax_amount) || 0) : 0), 0));
const grandTotal = computed(() => itemsTotal.value + chargesTotal.value + taxTotal.value);

function money(n: number): string {
    return n.toLocaleString(isAr.value ? 'ar' : 'en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

// ---- validation ----------------------------------------------------
const validLines = computed(() => lines.value.filter((l) => l.itemKey && Number(l.quantity) > 0));

// Lines the user picked an item for but left without a positive quantity — they
// must be completed or removed, never silently dropped from the submit.
const incompleteLines = computed(() => lines.value.filter(lineIncomplete));

const canSubmit = computed(() => {
    if (submitting.value) {
        return false;
    }
    if (validLines.value.length === 0) {
        return false;
    }
    // Never silently drop a started-but-incomplete line.
    if (incompleteLines.value.length > 0) {
        return false;
    }
    // No line may over-distribute or carry a negative cost.
    for (const l of validLines.value) {
        if (lineOverDistributed(l)) {
            return false;
        }
        if (Number(l.line_cost) < 0) {
            return false;
        }
    }
    // A charge, if added, needs a name + a positive amount.
    for (const c of charges.value) {
        if (c.name.trim() === '' || !(Number(c.amount) > 0)) {
            return false;
        }
    }
    return true;
});

// ---- submit --------------------------------------------------------
async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }
    submitting.value = true;
    submitError.value = null;

    const payload: CreatePurchaseReceiptPayload = {
        supplier_uuid: header.supplier_uuid || null,
        reference: header.reference || null,
        received_at: header.received_at || null,
        note: header.note || null,
        lines: validLines.value.map((l) => {
            const [kind, uuid] = l.itemKey.split(':');
            const allocations = l.allocations
                .filter((a) => a.branch_uuid && Number(a.quantity) > 0)
                .map((a) => ({ branch_uuid: a.branch_uuid, quantity: a.quantity }));
            return {
                item_type: kind === 'ingredient' ? 'ingredient' : 'product',
                item_uuid: uuid,
                quantity: l.quantity,
                line_cost: l.line_cost === '' ? 0 : l.line_cost,
                tax_amount: l.tax.tax_amount,
                tax_rate: l.tax.tax_rate,
                allocations: allocations.length > 0 ? allocations : undefined,
            };
        }),
        charges: charges.value
            .filter((c) => c.name.trim() !== '' && Number(c.amount) > 0)
            .map((c) => ({ name: c.name.trim(), category: c.category, amount: c.amount, tax_amount: c.tax.tax_amount, tax_rate: c.tax.tax_rate })),
    };

    try {
        const res = await createPurchaseReceipt(payload);
        void router.push({ name: 'merchant.purchase-receipts.show', params: { uuid: res.data.uuid } });
    } catch (e) {
        submitError.value = e instanceof ApiError
            ? (e.firstValidationMessage() ?? e.message ?? t('purchase_receipts.form.save_failed'))
            : t('purchase_receipts.form.save_failed');
    } finally {
        submitting.value = false;
    }
}

/**
 * Fetch EVERY unit/cooked product, walking all pages — listProducts clamps
 * per_page to 200 server-side, so a single call would silently hide a large
 * catalogue's tail. The receive picker must show every receivable product.
 */
async function fetchAllUnitProducts(): Promise<Product[]> {
    const all: Product[] = [];
    let pageNo = 1;
    let lastPageNo = 1;
    do {
        const res = await listProducts({ per_page: 200, page: pageNo });
        all.push(...res.data);
        lastPageNo = res.meta.last_page;
        pageNo += 1;
    } while (pageNo <= lastPageNo);
    // Only unit/cooked products hold stock (and can be received).
    return all.filter((p) => p.stock_mode === 'unit' || p.stock_mode === 'cooked');
}

onMounted(async () => {
    try {
        const [sup, ing, prod, phys, br, tax] = await Promise.all([
            listSuppliers(),
            listIngredients(),
            fetchAllUnitProducts(),
            listPhysicalItems(),
            listBranches(),
            listTaxes(),
        ]);
        suppliers.value = sup.data;
        ingredients.value = ing.data;
        products.value = prod;
        physicalItems.value = phys.data;
        branches.value = br.data;
        // PT — only ACTIVE taxes are offered as purchase-tax rates.
        taxes.value = tax.data.filter((x) => x.is_active);
        addLine();
    } catch (e) {
        refDataError.value = e instanceof ApiError ? (e.message || t('purchase_receipts.form.load_failed')) : t('purchase_receipts.form.load_failed');
    }
});
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-5xl pb-28">
            <div class="flex items-center gap-3">
                <ClipboardList class="size-7 text-teal-600" />
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">{{ t('purchase_receipts.form.title') }}</h1>
                    <p class="mt-0.5 text-sm text-slate-500">{{ t('purchase_receipts.form.subtitle') }}</p>
                </div>
            </div>

            <div v-if="refDataError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ refDataError }}
            </div>

            <!-- Header -->
            <div class="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.supplier') }}</span>
                    <select v-model="header.supplier_uuid" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <option value="">{{ t('purchase_receipts.form.no_supplier') }}</option>
                        <option v-for="s in suppliers" :key="s.uuid" :value="s.uuid">{{ s.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.reference') }}</span>
                    <input v-model="header.reference" type="text" maxlength="100" :placeholder="t('purchase_receipts.form.reference_ph')" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.received_at') }}</span>
                    <input v-model="header.received_at" type="date" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.note') }}</span>
                    <input v-model="header.note" type="text" maxlength="2000" :placeholder="t('purchase_receipts.form.note_ph')" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
            </div>

            <!-- Lines -->
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_receipts.form.items') }}</h2>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-100" @click="addLine">
                        <Plus class="size-4" /> {{ t('purchase_receipts.form.add_item') }}
                    </button>
                </div>

                <div class="mt-3 space-y-3">
                    <div v-for="(line, idx) in lines" :key="line.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="block min-w-[14rem] flex-1">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.item') }}</span>
                                <select v-model="line.itemKey" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    <option value="" disabled>{{ t('purchase_receipts.form.pick_item') }}</option>
                                    <optgroup :label="t('purchase_receipts.form.group_ingredients')">
                                        <option v-for="i in ingredients" :key="i.uuid" :value="`ingredient:${i.uuid}`">{{ itemName(i) }}</option>
                                    </optgroup>
                                    <optgroup :label="t('purchase_receipts.form.group_products')">
                                        <option v-for="p in products" :key="p.uuid" :value="`product:${p.uuid}`">{{ itemName(p) }}</option>
                                    </optgroup>
                                    <optgroup :label="t('purchase_receipts.form.group_physical')">
                                        <option v-for="p in physicalItems" :key="p.uuid" :value="`physical:${p.uuid}`">{{ itemName(p) }}</option>
                                    </optgroup>
                                </select>
                            </label>
                            <label class="block w-28">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.quantity') }}</span>
                                <div class="relative mt-1">
                                    <input v-model="line.quantity" type="number" step="0.001" min="0" placeholder="0" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </div>
                                <span v-if="lineUnit(line)" class="mt-0.5 block text-[10px] text-slate-400">{{ lineUnit(line) }}</span>
                            </label>
                            <label class="block w-32">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.cost') }}</span>
                                <input v-model="line.line_cost" type="number" step="0.001" min="0" placeholder="0.000" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <button type="button" class="mb-1.5 grid size-9 place-items-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" :aria-label="t('common.delete')" @click="removeLine(idx)">
                                <Trash2 class="size-4" />
                            </button>
                        </div>

                        <p v-if="lineIncomplete(line)" class="mt-2 text-xs text-rose-600">{{ t('purchase_receipts.form.needs_quantity') }}</p>

                        <!-- PT — optional tax on this line (disabled on a free line:
                             the backend books no tax when the cost is not positive). -->
                        <div class="mt-2.5">
                            <PurchaseTaxField v-model="line.tax" :base="Number(line.line_cost) || 0" :taxes="taxes" :disabled="!(Number(line.line_cost) > 0)" />
                        </div>

                        <!-- Inline branch split -->
                        <div class="mt-3 border-t border-slate-100 pt-3">
                            <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 transition hover:text-teal-800" @click="line.showAllocations = !line.showAllocations">
                                <component :is="line.showAllocations ? ChevronUp : ChevronDown" class="size-4" />
                                {{ t('purchase_receipts.form.distribute_now') }}
                            </button>

                            <div v-if="line.showAllocations" class="mt-3">
                                <p class="text-[11px] text-slate-400">{{ t('purchase_receipts.form.distribute_hint') }}</p>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <div v-for="alloc in line.allocations" :key="alloc.branch_uuid" class="flex items-center gap-2">
                                        <span class="flex-1 truncate text-sm text-slate-700">{{ branchName(alloc.branch_uuid) }}</span>
                                        <input v-model="alloc.quantity" type="number" step="0.001" min="0" placeholder="0" class="w-24 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                </div>
                                <p class="mt-2 text-xs" :class="lineOverDistributed(line) ? 'text-rose-600' : 'text-slate-500'">
                                    {{ t('purchase_receipts.form.remainder', { distributed: lineDistributed(line), total: Number(line.quantity) || 0, remainder: lineRemainder(line) }) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p v-if="lines.length === 0" class="rounded-lg border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-400">
                        {{ t('purchase_receipts.form.no_items') }}
                    </p>
                </div>
            </div>

            <!-- Charges -->
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_receipts.form.charges') }}</h2>
                        <p class="text-[11px] text-slate-400">{{ t('purchase_receipts.form.charges_hint') }}</p>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="addCharge">
                        <Plus class="size-4" /> {{ t('purchase_receipts.form.add_charge') }}
                    </button>
                </div>

                <div v-if="charges.length > 0" class="mt-3 space-y-2">
                    <div v-for="(charge, idx) in charges" :key="charge.id" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <label class="block min-w-[12rem] flex-1">
                            <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.charge_name') }}</span>
                            <input v-model="charge.name" type="text" maxlength="120" :placeholder="t('purchase_receipts.form.charge_name_ph')" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </label>
                        <label class="block w-40">
                            <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.charge_category') }}</span>
                            <select v-model="charge.category" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option v-for="c in chargeCategories" :key="c" :value="c">{{ t(`purchase_receipts.form.cat.${c}`) }}</option>
                            </select>
                        </label>
                        <label class="block w-32">
                            <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.amount') }}</span>
                            <input v-model="charge.amount" type="number" step="0.001" min="0" placeholder="0.000" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </label>
                        <button type="button" class="mb-1.5 grid size-9 place-items-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" :aria-label="t('common.delete')" @click="removeCharge(idx)">
                            <Trash2 class="size-4" />
                        </button>
                        <!-- PT — optional tax on this charge (disabled until a positive
                             amount; a zero charge books no expense and no tax). -->
                        <div class="w-full">
                            <PurchaseTaxField v-model="charge.tax" :base="Number(charge.amount) || 0" :taxes="taxes" :disabled="!(Number(charge.amount) > 0)" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sticky totals + submit -->
            <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur lg:start-72">
                <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-6 py-3">
                    <div class="flex items-center gap-4 text-sm">
                        <span class="text-slate-500">{{ t('purchase_receipts.form.items_total') }}: <span class="font-semibold tabular-nums text-slate-800">{{ money(itemsTotal) }}</span></span>
                        <span class="text-slate-500">{{ t('purchase_receipts.form.charges_total') }}: <span class="font-semibold tabular-nums text-slate-800">{{ money(chargesTotal) }}</span></span>
                        <span v-if="taxTotal > 0" class="text-slate-500">{{ t('purchase_receipts.form.tax_total') }}: <span class="font-semibold tabular-nums text-slate-800">{{ money(taxTotal) }}</span></span>
                        <span class="text-slate-700">{{ t('purchase_receipts.form.grand_total') }}: <span class="text-base font-bold tabular-nums text-teal-700">{{ money(grandTotal) }}</span></span>
                    </div>
                    <div class="ms-auto flex items-center gap-3">
                        <span v-if="submitError" class="text-xs text-rose-600">{{ submitError }}</span>
                        <RouterLink :to="{ name: 'merchant.purchase-receipts' }" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                            {{ t('common.cancel') }}
                        </RouterLink>
                        <button type="button" :disabled="!canSubmit" class="rounded-lg bg-teal-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50" @click="submit">
                            {{ submitting ? t('purchase_receipts.form.saving') : t('purchase_receipts.form.save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

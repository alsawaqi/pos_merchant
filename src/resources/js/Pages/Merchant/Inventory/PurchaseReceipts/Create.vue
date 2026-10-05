<script setup lang="ts">
/**
 * PD6 — Purchases (the Goods Received Note form; a full page, not a popup).
 *
 * One delivery, recorded in one submit. Add many lines mixing ingredients +
 * bought-in products + physical items; each line gets an amount + the price
 * paid and an OPTIONAL inline branch split (whatever is not split stays in the
 * central warehouse, to allocate later). Add any number of named extra charges
 * (delivery, customs…), each booking its own categorized expense. The backend
 * fans every line out to the existing receive/allocate/expense machinery in one
 * atomic transaction.
 *
 * Server gate: inventory.manage + access to all branches (it credits the
 * central warehouse).
 *
 * LAUNCH review add-on (C1, C2, D3, F) — a line is item → container (or a
 * physical item's pack) → pieces → amount (fills in as pieces × size; may be
 * LOWERED, never raised) → the price paid for the line (required; 0 = free,
 * the cost is not changed). The live line reads "= 24 × bottle 1 l = 24 l ·
 * 0.150 per l". "No container" keeps the free amount (a loose crate that
 * weighs more). The split is in pieces on a container line. A scan adds
 * "Milk · 1 × bottle 1.5 l"; the same scan again makes it 2.
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
import { listPhysicalItems, type PhysicalItem, type PhysicalItemPack } from '@/lib/api/physicalItems';
import { listTaxes, type Tax } from '@/lib/api/taxes';
import { createPurchaseReceipt, type CreatePurchaseReceiptPayload, type PurchaseReceiptLinePayload } from '@/lib/api/purchaseReceipts';
import type { ScanResult } from '@/lib/api/inventoryCodes';
import { purchaseCostWarning } from '@/lib/amountSafety';
import { amountInStored, amountProblem, containerLabel, containerLineText, containersOf, findContainer, friendly, type ContainerSource } from '@/lib/containers';
import { applyPurchaseScan } from '@/lib/scanApply';
import { kindUnits, unitOptionLabel } from '@/lib/itemKind';
import { useAmountConfirm } from '@/composables/useAmountConfirm';
import AmountConfirmDialog from '@/Pages/Merchant/Inventory/components/AmountConfirmDialog.vue';
import AmountInput from '@/Pages/Merchant/Inventory/components/AmountInput.vue';
import ScanBox from '@/Pages/Merchant/Inventory/components/ScanBox.vue';
import PurchaseTaxField, { type PurchaseTaxModel } from '@/Pages/Merchant/Inventory/PurchaseTaxField.vue';

const { t, locale } = useI18n();
const router = useRouter();
const isAr = computed(() => locale.value === 'ar');

// E2 — "Is this right?" before saving a price far from the current cost (warns, never blocks).
const { warnings: amountWarnings, confirm: confirmAmounts, answer: answerAmounts } = useAmountConfirm();

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
/** The split: in pieces on a container / pack line, else in the line's amount unit. */
interface AllocationRow { branch_uuid: string; quantity: string | number; }
interface LineRow {
    id: number; // stable v-for key — see nextRowId()
    itemKey: string; // "ingredient:uuid" | "product:uuid" | "physical:uuid"
    /** C1 / D3 — the container (ingredient) or pack (physical item); '' = none (type the amount). */
    container_uuid: string;
    /** How many of the container / pack. */
    pieces: string | number;
    /**
     * The amount: on a container line it fills in as pieces × size and may
     * only be lowered ('' = exactly that); with no container it is the
     * amount bought (a product / physical item: its pieces).
     */
    amount: string | number;
    /** '' = the stored unit, or a unit of the kind (kg / g, l / ml). */
    amount_unit: string;
    /** C2 — the price paid for this line (required; 0 = free, the cost is not changed). */
    line_cost: string | number;
    tax: PurchaseTaxModel; // PT — optional tax paid on this line
    showAllocations: boolean;
    allocations: AllocationRow[];
}
interface ChargeRow { id: number; name: string; category: string; amount: string | number; tax: PurchaseTaxModel; }

const header = reactive({
    supplier_uuid: '',
    // Phase B — '' = central warehouse; a branch uuid = the supplier
    // delivered the whole receipt straight to that branch.
    destination_branch_uuid: '',
    reference: '',
    received_at: new Date().toISOString().slice(0, 10),
    note: '',
    // AP — was this delivery bought on credit (pay later)? + optional due date.
    is_credit: false,
    due_date: '',
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

function blankLine(): LineRow {
    return { id: nextRowId(), itemKey: '', container_uuid: '', pieces: '', amount: '', amount_unit: '', line_cost: '', tax: { tax_amount: 0, tax_rate: null }, showAllocations: false, allocations: blankAllocations() };
}

function addLine(): void {
    lines.value.push(blankLine());
}

/** The bigger unit of the kind a loose amount starts in (kg for a g item, l for an ml one). */
function bigUnit(storedUnit: string): string {
    return storedUnit === 'g' ? 'kg' : storedUnit === 'ml' ? 'l' : '';
}

/**
 * C1 — a new item starts in its FIRST container (pieces typed next); an
 * ingredient with no container, a product and a physical item start with the
 * amount (a physical item may pick a pack).
 */
function onItemChange(line: LineRow): void {
    const ing = lineIngredient(line);
    line.container_uuid = ing ? (containersOf(ing)[0]?.uuid ?? '') : '';
    line.pieces = '';
    line.amount = '';
    line.amount_unit = ing ? bigUnit(ing.unit) : '';
    resetSplit(line);
}

/** Picking another container (or none) clears what was typed for the old one. */
function onContainerChange(line: LineRow): void {
    line.amount = '';
    const ing = lineIngredient(line);
    line.amount_unit = ing ? bigUnit(ing.unit) : '';
    if (line.container_uuid !== '' && String(line.pieces).trim() === '') line.pieces = '1';
    resetSplit(line);
}

function resetSplit(line: LineRow): void {
    for (const a of line.allocations) a.quantity = '';
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

function lineKind(line: LineRow): 'ingredient' | 'product' | 'physical' | null {
    if (!line.itemKey) return null;
    const kind = line.itemKey.split(':')[0];
    return kind === 'ingredient' || kind === 'product' || kind === 'physical' ? kind : null;
}

/** The ingredient a line picked, or null (a product line or nothing yet). */
function lineIngredient(line: LineRow): Ingredient | null {
    if (lineKind(line) !== 'ingredient') return null;
    const uuid = line.itemKey.split(':')[1];
    return ingredients.value.find((i) => i.uuid === uuid) ?? null;
}

/** The physical item a line picked, or null. */
function linePhysical(line: LineRow): PhysicalItem | null {
    if (lineKind(line) !== 'physical') return null;
    const uuid = line.itemKey.split(':')[1];
    return physicalItems.value.find((i) => i.uuid === uuid) ?? null;
}

/** The ingredient's chosen container, or null. */
function lineContainer(line: LineRow): ContainerSource | null {
    const ing = lineIngredient(line);
    return ing && line.container_uuid !== '' ? findContainer(ing, line.container_uuid) : null;
}

/** D3 — the physical item's chosen pack, or null. */
function linePack(line: LineRow): PhysicalItemPack | null {
    const item = linePhysical(line);
    return item && line.container_uuid !== '' ? ((item.packs ?? []).find((p) => p.uuid === line.container_uuid) ?? null) : null;
}

/** Whether the line is bought by container / pack (pieces typed). */
function byPieces(line: LineRow): boolean {
    return lineContainer(line) !== null || linePack(line) !== null;
}

/** The containers (or packs) the line may pick. */
function containerOptions(line: LineRow): { value: string; label: string }[] {
    const ing = lineIngredient(line);
    if (ing) return containersOf(ing).map((c) => ({ value: c.uuid, label: containerLabel(c, locale.value, ing.unit) }));
    const item = linePhysical(line);
    if (item) return (item.packs ?? []).map((p) => ({ value: p.uuid, label: isAr.value ? p.display_name_ar : p.display_name }));
    return [];
}

/** The units an ingredient's amount may be typed in ('' = the stored unit, then kg / g, l / ml…). */
function amountUnitOptions(line: LineRow): { value: string; label: string }[] {
    const ing = lineIngredient(line);
    if (!ing) return [];
    const units = kindUnits(ing.unit).filter((u) => u.value !== ing.unit);
    return [{ value: '', label: ing.unit }, ...units.map((u) => ({ value: u.value, label: unitOptionLabel(u.value, locale.value) }))];
}

function round4(n: number): number {
    return Math.round(n * 10000) / 10000;
}

/** What the pieces hold (the cap): in the stored unit for a container, in pieces for a pack. */
function lineCap(line: LineRow): number {
    const pieces = Number(line.pieces);
    if (!Number.isFinite(pieces) || pieces <= 0) return 0;
    const container = lineContainer(line);
    if (container) return round4(pieces * Number(container.factor));
    const pack = linePack(line);
    return pack ? round4(pieces * Number(pack.pieces)) : 0;
}

/** The amount TYPED, in the stored unit (an item's pieces for a product line); null when blank. */
function typedAmount(line: LineRow): number | null {
    const ing = lineIngredient(line);
    if (ing) return amountInStored(line.amount, line.amount_unit, ing.unit);
    const text = String(line.amount ?? '').trim();
    const n = Number(text);
    return text === '' || !Number.isFinite(n) ? null : round4(n);
}

/** The amount that goes into stock: typed, or (by container, nothing typed) what the pieces hold. */
function lineAmount(line: LineRow): number | null {
    const typed = typedAmount(line);
    return byPieces(line) ? (typed ?? (lineCap(line) > 0 ? lineCap(line) : null)) : typed;
}

/** C1 — the amount typed above what the pieces hold (never raised). */
function lineRaised(line: LineRow): boolean {
    return byPieces(line) && amountProblem(typedAmount(line), lineCap(line)) === 'raised';
}

function lineCost(line: LineRow): number {
    const n = Number(line.line_cost);
    return Number.isFinite(n) ? n : 0;
}

/** The unit an amount is read in on this line (the stored unit, or "pieces"). */
function lineStoredUnit(line: LineRow): string {
    return lineIngredient(line)?.unit ?? 'piece';
}

/** "= 24 × bottle 1 l = 24 l · 0.150 per l" (a free line: "Free: cost not changed"). */
function liveLine(line: LineRow): string | null {
    if (!line.itemKey) return null;
    const amount = lineAmount(line);
    const ing = lineIngredient(line);
    const parts: string[] = [];
    let costPer: { cost: string; unit: string } | null = null;
    let free = false;
    if (ing) {
        const container = lineContainer(line);
        const text = containerLineText({ container, all: containersOf(ing), pieces: line.pieces, amountStored: amount, storedUnit: ing.unit, lineCost: line.line_cost, locale: locale.value });
        const pieces = Number(line.pieces);
        if (text.leaf) parts.push(text.leaf);
        else if (container && Number.isFinite(pieces) && pieces > 0) parts.push(`${friendly(pieces, '').trim()} × ${containerLabel(container, locale.value, ing.unit)}`);
        if (text.amount) parts.push(text.amount);
        costPer = text.costPer;
        free = text.free;
    } else {
        if (amount !== null && amount > 0) parts.push(t('purchases_v2.pieces_total', { count: friendly(amount, '').trim() }));
        const cost = String(line.line_cost).trim() === '' ? NaN : Number(line.line_cost);
        free = Number.isFinite(cost) && cost === 0;
        if (Number.isFinite(cost) && cost > 0 && amount !== null && amount > 0) {
            costPer = { cost: (cost / amount).toFixed(3), unit: t('purchases_v2.piece') };
        }
    }
    if (parts.length === 0) return null;
    let text = `= ${parts.join(' = ')}`;
    if (costPer) text += ` · ${t('purchases_v2.cost_per', costPer)}`;
    else if (free) text += ` · ${t('purchases_v2.free')}`;
    return text;
}

function branchName(uuid: string): string {
    const b = branches.value.find((x) => x.uuid === uuid);
    return b ? itemName(b) : uuid;
}

// ---- live totals + per-line distribution --------------------------
/** The total the split is taken from: pieces on a container / pack line, else the typed amount. */
function lineSplitTotal(line: LineRow): number {
    return (byPieces(line) ? Number(line.pieces) : Number(line.amount)) || 0;
}

function lineDistributed(line: LineRow): number {
    return line.allocations.reduce((sum, a) => sum + (Number(a.quantity) || 0), 0);
}

function lineRemainder(line: LineRow): number {
    return lineSplitTotal(line) - lineDistributed(line);
}

function lineOverDistributed(line: LineRow): boolean {
    return lineRemainder(line) < -1e-9;
}

/** A line the user STARTED (picked an item) but left without pieces / an amount. */
function lineIncomplete(line: LineRow): boolean {
    if (line.itemKey === '') return false;
    return byPieces(line) ? !(Number(line.pieces) > 0) : !(Number(line.amount) > 0);
}

/** C2 — the price paid is required on every line (0 = free). */
function lineNeedsPrice(line: LineRow): boolean {
    return line.itemKey !== '' && (String(line.line_cost).trim() === '' || Number(line.line_cost) < 0);
}

const itemsTotal = computed(() => lines.value.reduce((sum, l) => sum + lineCost(l), 0));
const chargesTotal = computed(() => charges.value.reduce((sum, c) => sum + (Number(c.amount) || 0), 0));
// PT — Σ of every line + charge tax; grand = items + charges + tax (gross).
// Mirror the backend's rule (tax only counts when the base cost is positive — a
// free line / zero charge books no tax) so this preview matches the persisted
// receipt totals exactly.
const taxTotal = computed(() =>
    lines.value.reduce((s, l) => s + (lineCost(l) > 0 ? (Number(l.tax.tax_amount) || 0) : 0), 0)
    + charges.value.reduce((s, c) => s + (Number(c.amount) > 0 ? (Number(c.tax.tax_amount) || 0) : 0), 0));
const grandTotal = computed(() => itemsTotal.value + chargesTotal.value + taxTotal.value);

function money(n: number): string {
    return n.toLocaleString(isAr.value ? 'ar' : 'en-GB', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

// ---- validation ----------------------------------------------------
const validLines = computed(() => lines.value.filter((l) => l.itemKey && !lineIncomplete(l)));

// Lines the user picked an item for but left without pieces / an amount — they
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
    // No line may over-distribute, raise its amount or miss its price.
    for (const l of validLines.value) {
        if (lineOverDistributed(l) || lineRaised(l) || lineNeedsPrice(l)) {
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

// ---- scan box -------------------------------------------------------
/**
 * F — a scan adds "Milk · 1 × bottle 1.5 l"; the same scan again makes it 2.
 * A code naming only the item adds it in its first container.
 */
const scanMessage = ref<string | null>(null);

/**
 * Fix order B-1 (M5, L10) — the pure lib/scanApply applyPurchaseScan (node
 * tested): an item-level code scanned again makes the line 2 too, and an item
 * Purchases refuses (a prep item, a cooked / combo product) is never added.
 */
function onScan(result: ScanResult): void {
    const outcome = applyPurchaseScan(lines.value, result, { blankLine, onItemChange });
    scanMessage.value = outcome.ok ? null : scanRefusalText(outcome.reason, result.item?.name ?? '');
}

function scanRefusalText(reason: string, item: string): string {
    if (reason === 'not_purchasable_prep') return t('purchases_v2.scan_prep', { item });
    if (reason === 'not_purchasable') return t('purchases_v2.scan_not_bought_in', { item });
    return t('scan.wrong_item', { item });
}

// ---- submit --------------------------------------------------------
/** The wire line: a container / pack line sends pieces (+ a lowered amount); a loose line its amount. */
function linePayload(l: LineRow): PurchaseReceiptLinePayload {
    const [kind, uuid] = l.itemKey.split(':');
    const pieces = byPieces(l);
    // Phase B — a direct-to-branch receipt claims every line in full;
    // per-line splits are dropped (the server rejects mixing them).
    const allocations = header.destination_branch_uuid
        ? []
        : l.allocations
            .filter((a) => a.branch_uuid && Number(a.quantity) > 0)
            .map((a) => (pieces ? { branch_uuid: a.branch_uuid, pieces: a.quantity } : { branch_uuid: a.branch_uuid, quantity: a.quantity }));
    const base = {
        item_type: (kind === 'ingredient' ? 'ingredient' : 'product') as 'ingredient' | 'product',
        item_uuid: uuid ?? '',
        // C2 — the price paid for the whole line (0 = free).
        line_cost: l.line_cost,
        tax_amount: l.tax.tax_amount,
        tax_rate: l.tax.tax_rate,
        allocations: allocations.length > 0 ? allocations : undefined,
    };
    const typed = String(l.amount).trim();
    if (pieces) {
        return {
            ...base,
            ...(kind === 'ingredient' ? { container_uuid: l.container_uuid } : { pack_uuid: l.container_uuid }),
            pieces: l.pieces,
            ...(typed !== '' ? { amount: typed, amount_unit: kind === 'ingredient' && l.amount_unit !== '' ? l.amount_unit : null } : {}),
        };
    }
    return {
        ...base,
        quantity: l.amount,
        unit: kind === 'ingredient' && l.amount_unit !== '' ? l.amount_unit : null,
    };
}

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }
    // E2 — a price per kg / l / piece far from the current cost (skipped with no cost yet).
    const warnings = validLines.value.map((l) => {
        const ing = lineIngredient(l);
        const amount = lineAmount(l);
        if (!ing || ing.has_cost === false || amount === null || !(amount > 0)) return null;
        const warning = purchaseCostWarning(lineCost(l) / amount, ing.default_unit_cost);
        return warning ? { ...warning, params: { ...warning.params, item: itemName(ing) } } : null;
    });
    if (!(await confirmAmounts(warnings))) {
        return;
    }
    submitting.value = true;
    submitError.value = null;

    const payload: CreatePurchaseReceiptPayload = {
        supplier_uuid: header.supplier_uuid || null,
        destination_branch_uuid: header.destination_branch_uuid || null,
        reference: header.reference || null,
        received_at: header.received_at || null,
        note: header.note || null,
        is_credit: header.is_credit,
        due_date: header.is_credit ? (header.due_date || null) : null,
        lines: validLines.value.map(linePayload),
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
 * Fetch EVERY ready/bought-in (unit) product, walking all pages — listProducts
 * clamps per_page to 200 server-side, so a single call would silently hide a
 * large catalogue's tail. The receive picker must show every buyable product.
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
    // A receipt records BOUGHT goods, so only ready/bought-in ('unit') products
    // are purchasable here. Cooked + made-to-order are recipe/kitchen-driven
    // (their shelf is filled by production, not bought), so they're excluded.
    return all.filter((p) => p.stock_mode === 'unit');
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
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.destination') }}</span>
                    <select v-model="header.destination_branch_uuid" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        <option value="">{{ t('purchase_receipts.form.destination_central') }}</option>
                        <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                    </select>
                    <span v-if="header.destination_branch_uuid" class="mt-1 block text-[11px] text-slate-500">{{ t('purchase_receipts.form.destination_hint') }}</span>
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

            <!-- AP — payment terms: paid now vs bought on credit (pay later). -->
            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.terms') }}</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                        :class="!header.is_credit ? 'border-teal-500 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        @click="header.is_credit = false"
                    >
                        {{ t('purchase_receipts.payment.paid_in_full') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                        :class="header.is_credit ? 'border-amber-500 bg-amber-50 text-amber-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        @click="header.is_credit = true"
                    >
                        {{ t('purchase_receipts.payment.on_credit') }}
                    </button>
                </div>
                <label v-if="header.is_credit" class="mt-3 block max-w-xs">
                    <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.payment.due_date_optional') }}</span>
                    <input v-model="header.due_date" type="date" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                </label>
                <p v-if="header.is_credit" class="mt-2 text-xs text-slate-400">{{ t('purchase_receipts.payment.credit_hint') }}</p>
            </div>

            <!-- Lines -->
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('purchase_receipts.form.items') }}</h2>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm font-semibold text-teal-700 transition hover:bg-teal-100" @click="addLine">
                        <Plus class="size-4" /> {{ t('purchase_receipts.form.add_item') }}
                    </button>
                </div>

                <!-- F — scan a container, pack or item barcode (or type a SKU) and press Enter. -->
                <ScanBox
                    class="mt-3"
                    can-link
                    :ingredients="ingredients"
                    :physical-items="physicalItems"
                    :item-types="['ingredient', 'physical', 'product']"
                    data-test="purchase-scan"
                    @found="onScan"
                />
                <p v-if="scanMessage" class="mt-1 text-xs font-semibold text-amber-700" data-test="purchase-scan-refused">{{ scanMessage }}</p>

                <div class="mt-3 space-y-3">
                    <div v-for="(line, idx) in lines" :key="line.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-test="purchase-line">
                        <div class="flex flex-wrap items-start gap-3">
                            <label class="block min-w-[14rem] flex-1">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchase_receipts.form.item') }}</span>
                                <select v-model="line.itemKey" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" @change="onItemChange(line)">
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
                            <!-- C1 / D3 — the container (or pack), or none: type the amount (a loose crate that weighs more). -->
                            <label v-if="containerOptions(line).length > 0" class="block w-48">
                                <span class="text-xs font-medium text-slate-600">{{ lineIngredient(line) ? t('purchases_v2.container') : t('purchases_v2.pack') }}</span>
                                <select v-model="line.container_uuid" data-test="line-container" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" @change="onContainerChange(line)">
                                    <option v-for="o in containerOptions(line)" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    <option value="">{{ lineIngredient(line) ? t('purchases_v2.no_container') : t('purchases_v2.no_pack') }}</option>
                                </select>
                            </label>
                            <label v-if="byPieces(line)" class="block w-24">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchases_v2.pieces') }}</span>
                                <input v-model="line.pieces" type="number" step="any" min="0" placeholder="0" data-test="line-pieces" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm tabular-nums shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <!-- C1 — the amount fills in as pieces × size; it may be lowered, never raised. -->
                            <div v-if="line.itemKey" class="block w-56">
                                <span class="text-xs font-medium text-slate-600">{{ byPieces(line) ? t('purchases_v2.amount_lower_only') : t('purchases_v2.amount') }}</span>
                                <AmountInput
                                    v-model="line.amount"
                                    v-model:unit="line.amount_unit"
                                    class="mt-1"
                                    :options="lineIngredient(line) ? amountUnitOptions(line) : null"
                                    :stored-unit="lineStoredUnit(line)"
                                    :placeholder="byPieces(line) && lineCap(line) > 0 ? friendly(lineCap(line), lineStoredUnit(line) === 'piece' ? '' : lineStoredUnit(line)).trim() : '0'"
                                    :input-class="`block w-full rounded-lg border bg-white px-3 py-2 text-sm tabular-nums shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 ${lineRaised(line) ? 'border-rose-400 bg-rose-50 text-rose-700' : 'border-slate-200'}`"
                                    select-class="shrink-0 rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm"
                                    data-test="line-amount"
                                />
                            </div>
                            <!-- C2 — what was paid for the whole line (required; 0 = free). -->
                            <label class="block w-36">
                                <span class="text-xs font-medium text-slate-600">{{ t('purchases_v2.price_paid') }} *</span>
                                <input v-model="line.line_cost" type="number" step="0.001" min="0" placeholder="0.000" required data-test="line-cost" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm tabular-nums shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </label>
                            <button type="button" class="mt-5 grid size-9 place-items-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" :aria-label="t('common.delete')" @click="removeLine(idx)">
                                <Trash2 class="size-4" />
                            </button>
                        </div>

                        <p v-if="lineIncomplete(line)" class="mt-2 text-xs text-rose-600">{{ byPieces(line) ? t('purchases_v2.needs_pieces') : t('purchase_receipts.form.needs_quantity') }}</p>
                        <p v-if="lineRaised(line)" class="mt-2 text-xs font-semibold text-rose-600" data-test="line-raised">{{ t('containers.total_raised', { cap: friendly(lineCap(line), lineStoredUnit(line) === 'piece' ? '' : lineStoredUnit(line)).trim() }) }}</p>
                        <p v-if="lineNeedsPrice(line)" class="mt-2 text-xs text-rose-600" data-test="line-needs-price">{{ t('purchases_v2.needs_price') }}</p>
                        <!-- C1 — the live line: "= 24 × bottle 1 l = 24 l · 0.150 per l". -->
                        <p v-if="liveLine(line)" class="mt-2 text-xs font-medium text-teal-700" data-test="line-live">
                            <bdi dir="ltr">{{ liveLine(line) }}</bdi>
                        </p>

                        <!-- PT — optional tax on this line (disabled on a free line:
                             the backend books no tax when the cost is not positive). -->
                        <div class="mt-2.5">
                            <PurchaseTaxField v-model="line.tax" :base="lineCost(line)" :taxes="taxes" :disabled="!(lineCost(line) > 0)" />
                        </div>

                        <!-- Inline branch split -->
                        <div class="mt-3 border-t border-slate-100 pt-3">
                            <button v-if="!header.destination_branch_uuid" type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-700 transition hover:text-teal-800" @click="line.showAllocations = !line.showAllocations">
                                <component :is="line.showAllocations ? ChevronUp : ChevronDown" class="size-4" />
                                {{ t('purchase_receipts.form.distribute_now') }}
                            </button>

                            <div v-if="line.showAllocations && !header.destination_branch_uuid" class="mt-3">
                                <p class="text-[11px] text-slate-400">{{ t('purchase_receipts.form.distribute_hint') }}</p>
                                <!-- C3 — a container line splits in pieces ("2 crates to Branch A"). -->
                                <p v-if="byPieces(line)" class="text-[11px] text-slate-500" data-test="split-in-pieces">{{ t('purchases_v2.split_in_pieces', { container: containerOptions(line).find((o) => o.value === line.container_uuid)?.label ?? '' }) }}</p>
                                <p v-else-if="lineIngredient(line)" class="text-[11px] text-slate-500">{{ t('purchase_receipts.form.split_in_unit', { unit: line.amount_unit || lineStoredUnit(line) }) }}</p>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <div v-for="alloc in line.allocations" :key="alloc.branch_uuid" class="flex items-center gap-2">
                                        <span class="flex-1 truncate text-sm text-slate-700">{{ branchName(alloc.branch_uuid) }}</span>
                                        <input v-model="alloc.quantity" type="number" step="any" min="0" placeholder="0" class="w-24 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                </div>
                                <p class="mt-2 text-xs" :class="lineOverDistributed(line) ? 'text-rose-600' : 'text-slate-500'">
                                    {{ t('purchase_receipts.form.remainder', { distributed: lineDistributed(line), total: lineSplitTotal(line), remainder: lineRemainder(line) }) }}
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

        <!-- E2 — "Is this right?" (warns, never blocks). -->
        <AmountConfirmDialog :warnings="amountWarnings" @answer="answerAmounts" />
    </MerchantLayout>
</template>

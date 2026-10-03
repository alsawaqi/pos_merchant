<script setup lang="ts">
/**
 * Inventory — Phase 5a.
 *
 * Tabbed page: Ingredients | Suppliers | Branch Stock | Movements.
 *
 *   - Ingredients tab: company-wide master list. CRUD with
 *     unit selector + cost/threshold/supplier inputs.
 *   - Suppliers tab: lightweight per-merchant directory.
 *   - Branch Stock tab: branch picker at top + sortable list
 *     of (ingredient, quantity, health, last_movement). Per-row
 *     Adjust + Restock buttons open dedicated modals.
 *   - Movements tab: paginated append-only ledger with filters
 *     for ingredient + type.
 *
 * Permission gating:
 *   - Page reachable when InventoryView is granted.
 *   - Create / edit / delete buttons + Adjust / Restock only
 *     when InventoryManage is granted. Server is the real gate.
 */

import {
    AlertTriangle,
    ArrowLeftRight,
    Boxes,
    Building2,
    Check,
    ChefHat,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    History,
    Image as ImageIcon,
    Lightbulb,
    Minus,
    Package,
    Pencil,
    Plus,
    Send,
    ShoppingCart,
    Trash,
    Trash2,
    Truck,
    Users,
    X,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import IngredientStockDialog from './IngredientStockDialog.vue';
import PrepItemsTab from './PrepItemsTab.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    adjustStock,
    allocateRestockRequest,
    resolvePurchasedRestockRequest,
    approveRestockRequest,
    cancelRestockRequest,
    createBranchTransfer,
    createIngredient,
    createIngredientUnit,
    createRestockRequest,
    createSupplier,
    deleteIngredient,
    deleteIngredientUnit,
    deleteSupplier,
    getInventorySettings,
    ingredientUnitFactor,
    ingredientUnitOptions,
    listBranchStock,
    listBranchTransfers,
    listIngredients,
    listIngredientUnits,
    listRestockRequests,
    listStockCounts,
    listStockMovements,
    listSuppliers,
    listWaste,
    recordPurchase,
    recordWaste,
    rejectRestockRequest,
    restockStock,
    submitRestockRequest,
    submitStockCount,
    updateIngredient,
    updateIngredientUnit,
    updateRestockRequest,
    updateSupplier,
    type BranchStockMeta,
    type BranchStockRow,
    type BranchTransfer,
    type BranchTransferLinePayload,
    type Ingredient,
    type IngredientAltUnit,
    type IngredientUnit,
    type InventoryStatus,
    type PaginatedMovements,
    type PaginatedStockCounts,
    type PaginatedWaste,
    type StockCountLinePayload,
    getRestockSuggestions,
    type RestockLinePayload,
    type RestockRequest,
    type RestockRequestLine,
    type RestockRequestStatus,
    type RestockSuggestion,
    type StockMovementType,
    type Supplier,
    type WasteReason,
    type WasteRecord,
} from '@/lib/api/inventory';
import {
    createPhysicalItem,
    deletePhysicalItem,
    listPhysicalItems,
    updatePhysicalItem,
    type PhysicalItem,
    type PhysicalItemPurpose,
} from '@/lib/api/physicalItems';
import ProductStockDialog from '@/Pages/Merchant/Catalogue/ProductStockDialog.vue';
import {
    ITEM_KINDS,
    costUnit,
    friendlyAmount,
    friendlyCost,
    holdsEntry,
    isLegacyStoredUnit,
    kindOfUnit,
    kindUnits,
    PIECE_UNIT,
    storedUnitForKind,
    toStoredAmount,
    toStoredCost,
    trimAmount,
    unitOptionLabel,
    type ItemKind,
    type KindUnit,
} from '@/lib/itemKind';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const route = useRoute();

const isArabic = computed(() => locale.value === 'ar');
const canViewInventory = computed(() => can(MerchantPermission.InventoryView));
const canManage = computed(() => can(MerchantPermission.InventoryManage));
// Phase 5c — split restock permissions: create on the requester
// side, review on the HQ side. Either one gives the user access
// to the Restock Requests tab (it's READ-also for InventoryView).
const canCreateRestock = computed(() => can(MerchantPermission.RestockRequestCreate));
const canReviewRestock = computed(() => can(MerchantPermission.RestockRequestReview));

type TabKey = 'ingredients' | 'physical_items' | 'prep_items' | 'suppliers' | 'stock' | 'movements' | 'waste' | 'stock_counts' | 'restock_requests' | 'transfers';
const activeTab = ref<TabKey>('ingredients');

// =================== Shared data =================================

const branches = ref<Branch[]>([]);
const selectedBranchUuid = ref<string | null>(null);

const ingredients = ref<Ingredient[]>([]);
// LAUNCH-P3 P3-4 — prep items: no stock (never on the stock screens), but a
// sauce can still be thrown away: the waste picker offers them, and the
// server records the waste of their raw ingredients.
const prepIngredients = ref<Ingredient[]>([]);
const suppliers = ref<Supplier[]>([]);

// PD3a — physical items: things that CANNOT be eaten (cups, boxes,
// bulbs, cleaning items). Created + managed ONLY here; the rows ride
// the product piece-counting machinery under the hood, so the stock
// dialog below is the same one unit products use.
const physicalItems = ref<PhysicalItem[]>([]);
const physicalItemModalOpen = ref(false);
const physicalItemModalBusy = ref(false);
const physicalItemModalMode = ref<'create' | 'edit'>('create');
const physicalItemModalTarget = ref<PhysicalItem | null>(null);
const physicalItemModalErrors = ref<Record<string, string[]>>({});
const physicalItemModalError = ref<string | null>(null);
const physicalItemForm = reactive<{
    name: string;
    name_ar: string;
    purpose: PhysicalItemPurpose;
    cost_price: string;
    low_stock_threshold: string;
    status: 'active' | 'inactive';
}>({ name: '', name_ar: '', purpose: 'packaging', cost_price: '', low_stock_threshold: '', status: 'active' });
// Stock dialog target (receive w/ cost -> expense, distribute, transfer...).
const physicalItemStockTarget = ref<PhysicalItem | null>(null);
// Delete confirm (the server 422s while the item is still attached to
// a product's composition — that message surfaces on the page banner).
const physicalItemDeleteTarget = ref<PhysicalItem | null>(null);
const physicalItemDeleting = ref(false);
const branchStock = ref<BranchStockRow[]>([]);
// LAUNCH-P2 P2-7 — every ingredient of the branch; 'low' keeps negative and
// below-minimum rows only. meta = branch value + counts.
const stockFilter = ref<'all' | 'low'>('all');
const branchStockMeta = ref<BranchStockMeta | null>(null);
// LAUNCH-P2 P2-4 — stock comes in through Goods received only while on:
// the Restock / Purchase entry points stay hidden. Hidden until loaded.
const singleStockIn = ref(true);
const movements = ref<PaginatedMovements | null>(null);

// Phase 5c — waste + restock-request state.
const waste = ref<PaginatedWaste | null>(null);
const wasteFilters = reactive<{ ingredient_uuid: string; reason: WasteReason | '' }>({
    ingredient_uuid: '',
    reason: '',
});
const wastePage = ref(1);

const restockRequests = ref<RestockRequest[]>([]);
const restockFilters = reactive<{ status: RestockRequestStatus | ''; branch_uuid: string }>({
    status: '',
    branch_uuid: '',
});

// Phase 6 — branch→branch transfer state. NOT branch-scoped (the
// list shows every transfer; an optional filter narrows to one
// branch on either side). An immediate atomic move, no lifecycle.
const branchTransfers = ref<BranchTransfer[]>([]);
const transferFilters = reactive<{ branch_uuid: string }>({ branch_uuid: '' });

const transferModalOpen = ref(false);
const transferModalBusy = ref(false);
const transferModalError = ref<string | null>(null);
const transferModalErrors = ref<Record<string, string[]>>({});
const transferForm = reactive<{
    from_branch_uuid: string;
    to_branch_uuid: string;
    note: string;
    lines: { ingredient_uuid: string; quantity: string; unit: string }[];
}>({ from_branch_uuid: '', to_branch_uuid: '', note: '', lines: [] });

const loading = ref(true);
const error = ref<string | null>(null);
// Page-level success banner (emerald) — mirrors the rose `error`
// banner. Used by the restock-suggestions create flow, which
// closes its panel on success rather than showing an in-panel note.
const success = ref<string | null>(null);

// =================== Ingredient modal =============================

const ingModalOpen = ref(false);
const ingModalBusy = ref(false);
const ingModalMode = ref<'create' | 'edit'>('create');
const ingModalTarget = ref<Ingredient | null>(null);
const ingModalErrors = ref<Record<string, string[]>>({});
const ingModalError = ref<string | null>(null);
const ingForm = reactive<{
    name: string;
    name_ar: string;
    /** '' until the kind question is answered on a new ingredient. */
    unit: IngredientUnit | '';
    piece_unit_label: string;
    piece_unit_label_ar: string;
    /** A5 — what the count container holds ("1.5" + "l"); sent as units_per_piece in the stored unit. */
    container_amount: string;
    container_unit: string;
    allow_fractional_pieces: boolean;
    /** F1 — the cost as typed, per cost_unit (per kg / l by default); sent per stored unit. */
    default_unit_cost: string;
    cost_unit: string;
    min_stock_threshold: string;
    /** A7 — the unit of the kind the minimum is typed in (sent in the stored unit). */
    min_stock_unit: string;
    primary_supplier_id: number | null;
    status: InventoryStatus;
}>({
    name: '',
    name_ar: '',
    unit: 'g',
    piece_unit_label: '',
    piece_unit_label_ar: '',
    container_amount: '',
    container_unit: '',
    allow_fractional_pieces: true,
    default_unit_cost: '0.000',
    cost_unit: '',
    min_stock_threshold: '',
    min_stock_unit: '',
    primary_supplier_id: null,
    status: 'active',
});

// LAUNCH item kind (owner decision 2026-10-03) — a new ingredient is asked
// what KIND of item it is, never a base unit: Weighed is stored in g, Liquid
// in ml, Counted in pieces ("buy big, use small", LAUNCH-P2 P2-1). kg, l and
// pack sizes stay available wherever an amount is typed.
const ingKind = computed<ItemKind | null>(() => (ingForm.unit === '' ? null : kindOfUnit(ingForm.unit)));

/**
 * A2 — the edit form shows the kind; it can change only while today's
 * unit-change rule allows it (an unused ingredient). The server works that
 * out for the list (unit_locked) and still refuses on save.
 */
const kindLocked = computed<boolean>(() => ingModalMode.value === 'edit' && ingModalTarget.value?.unit_locked === true);

/** A2 — an older ingredient stored in kg / l / pack / box: its kind plus a small "stored in kg" note. */
const legacyStoredUnit = computed<string | null>(() => (ingForm.unit !== '' && isLegacyStoredUnit(ingForm.unit) ? ingForm.unit : null));

/**
 * F6 — an unused ingredient that already has pack sizes or a count container
 * cannot change kind (they hold amounts of the stored unit): the form says
 * so as soon as another kind is picked, and does not send it (the server
 * refuses it too).
 */
const kindChangeBlocked = computed<boolean>(() => ingModalMode.value === 'edit'
    && ingModalTarget.value !== null
    && ingForm.unit !== ''
    && kindOfUnit(ingForm.unit) !== kindOfUnit(ingModalTarget.value.unit)
    && (altUnits.value.length > 0 || ingForm.piece_unit_label.trim() !== ''));

function chooseKind(kind: ItemKind): void {
    if (kindLocked.value) return;
    // Back to the ingredient's own kind keeps its stored unit (an older kg stays kg).
    ingForm.unit = storedUnitForKind(kind, ingModalTarget.value?.unit ?? null) as IngredientUnit;
}

/** The units a pack size (or the count container) can hold: kg/g, l/ml, or pieces. */
const holdUnits = computed<KindUnit[]>(() => kindUnits(ingForm.unit));

function holdUnitLabel(unit: string): string {
    // G1 — a US unit says its size: "gallon (3.785 l)".
    return unit === 'piece' || unit === 'pack' || unit === 'box' ? unitLabel(unit) : unitOptionLabel(unit, locale.value);
}

// =================== LAUNCH item kind, A3 — pack sizes on create ===
// "How do you buy it?" (optional): crate holds 12 l, sack holds 25 kg, box
// holds 24 pieces. Sent with the ingredient (pack_sizes[]) and saved in the
// same transaction; the server works out each factor from the amount.

interface PackSizeDraft {
    name: string;
    name_ar: string;
    amount: string;
    unit: string;
}
const packSizeDrafts = ref<PackSizeDraft[]>([]);

function addPackSizeDraft(): void {
    packSizeDrafts.value.push({ name: '', name_ar: '', amount: '', unit: holdUnits.value[0]?.value ?? '' });
}

function removePackSizeDraft(index: number): void {
    packSizeDrafts.value.splice(index, 1);
}

// A kind change moves every pack row to a unit of the new kind.
watch(
    () => ingForm.unit,
    () => {
        const allowed = holdUnits.value.map((u) => u.value);
        for (const draft of packSizeDrafts.value) {
            if (!allowed.includes(draft.unit)) draft.unit = allowed[0] ?? '';
        }
    },
);

// A5 — the count container's unit follows the kind too (A7: and the minimum's).
watch(
    () => ingForm.unit,
    () => {
        const allowed = holdUnits.value.map((u) => u.value);
        if (!allowed.includes(ingForm.container_unit)) ingForm.container_unit = allowed[0] ?? '';
        if (!allowed.includes(ingForm.min_stock_unit)) ingForm.min_stock_unit = allowed[0] ?? '';
        // F1 — the cost is per kg / l by default.
        if (!allowed.includes(ingForm.cost_unit)) ingForm.cost_unit = ingForm.unit === '' ? '' : costUnit(ingForm.unit);
    },
);

/**
 * F1 — the cost per STORED unit: "0.150" per l on a ml item → "0.00015".
 * Unchanged on edit: the saved value goes back as it was (no audit noise);
 * anything that does not convert goes as typed (the server explains).
 */
function costInStoredUnit(storedUnit: string): string {
    const text = String(ingForm.default_unit_cost ?? '').trim();
    const stored = toStoredCost(text, ingForm.cost_unit, storedUnit);
    if (text === '' || stored === null) return text;
    const saved = ingModalTarget.value?.default_unit_cost ?? null;
    if (saved !== null && ingModalTarget.value?.unit === storedUnit && Math.abs(parseFloat(saved) - stored) < 1e-9) return saved;
    return String(stored);
}

/**
 * A7 — the minimum stock in the stored unit: "5" kg on a g item → "5000".
 * Blank = no minimum; 0 stays 0; anything that does not convert goes as
 * typed (the server explains).
 */
function minimumInStoredUnit(storedUnit: string): string | null {
    const text = String(ingForm.min_stock_threshold ?? '').trim();
    if (text === '') return null;
    const stored = toStoredAmount(text, ingForm.min_stock_unit, storedUnit);
    if (stored === null) return text;
    // Unchanged on edit: send the saved value back as it was (no audit noise).
    const saved = ingModalTarget.value?.min_stock_threshold ?? null;
    if (saved !== null && ingModalTarget.value?.unit === storedUnit && parseFloat(saved) === stored) return saved;
    return trimAmount(stored);
}

/**
 * A5 — what the count container holds, in the stored unit (units_per_piece):
 * "bottle holds 1.5 l" on a ml item → "1500". Blank = no container. An
 * amount that does not convert (0, negative) goes as typed, so the server
 * explains it next to the field.
 */
function containerUnitsPerPiece(storedUnit: string): string | null {
    const text = String(ingForm.container_amount ?? '').trim();
    if (text === '') return null;
    const stored = toStoredAmount(text, ingForm.container_unit, storedUnit);
    return stored === null ? text : trimAmount(stored);
}

/** The rows to send: a fully blank row is left out; anything typed is sent (the server explains what is missing). */
function packSizesPayload(): { name: string; name_ar: string | null; amount: string; unit: string }[] {
    return packSizeDrafts.value
        .filter((d) => d.name.trim() !== '' || d.name_ar.trim() !== '' || String(d.amount ?? '').trim() !== '')
        .map((d) => ({ name: d.name.trim(), name_ar: d.name_ar.trim() || null, amount: String(d.amount ?? '').trim(), unit: d.unit }));
}

/** The first server error of one pack row (name, amount or unit). */
function packSizeError(index: number): string | null {
    for (const field of ['name', 'amount', 'unit']) {
        const messages = ingModalErrors.value[`pack_sizes.${index}.${field}`];
        if (messages && messages.length > 0) return messages[0]!;
    }
    return null;
}

// =================== Alternate units (v2 #13) ====================
// Sub-editor inside the ingredient EDIT modal. Each row maps to a
// separate CRUD endpoint under the ingredient uuid, so changes
// persist immediately (add/save-factor/delete) rather than riding
// the parent form submit. Only meaningful in edit mode — a brand-
// new ingredient has no uuid yet, so the section shows a
// "save first" hint instead. `factor` stays a STRING end-to-end.

const altUnits = ref<IngredientAltUnit[]>([]);
const altUnitsLoading = ref(false);
const altUnitsError = ref<string | null>(null);
// Per-row inline field errors keyed by unit uuid (plus '' for the
// add-new row), so a 422 highlights the exact row.
const altUnitFieldErrors = ref<Record<string, Record<string, string[]>>>({});
// uuid of the row whose save/delete is currently in flight.
const altUnitBusyUuid = ref<string | null>(null);
// LAUNCH item kind, A4 — these are the item's PACK SIZES: each row says
// what the pack holds ("crate holds 12 l", an amount + a unit of the kind);
// the server works out the factor. Nobody types a factor.
// New-row draft.
const altUnitNew = reactive<{ name: string; name_ar: string; amount: string; unit: string }>({
    name: '',
    name_ar: '',
    amount: '',
    unit: '',
});
const altUnitNewBusy = ref(false);
// Editable buffers for existing rows, keyed by unit uuid. Lets the
// user tweak what it holds / Arabic name without mutating the source
// list until they hit Save.
const altUnitDrafts = reactive<Record<string, { name_ar: string; amount: string; unit: string }>>({});

/** A4 — pack sizes are saved straight away, so they hold units of the SAVED kind. */
const savedHoldUnits = computed<KindUnit[]>(() => kindUnits(ingModalTarget.value?.unit));

// =================== Supplier modal ==============================

const supModalOpen = ref(false);
const supModalBusy = ref(false);
const supModalMode = ref<'create' | 'edit'>('create');
const supModalTarget = ref<Supplier | null>(null);
const supModalErrors = ref<Record<string, string[]>>({});
const supModalError = ref<string | null>(null);
const supForm = reactive<{
    name: string;
    contact: string;
    notes: string;
    status: InventoryStatus;
}>({ name: '', contact: '', notes: '', status: 'active' });

// =================== Adjust + Restock modals =====================

const adjustOpen = ref(false);
const adjustBusy = ref(false);
const adjustError = ref<string | null>(null);
const adjustErrors = ref<Record<string, string[]>>({});
const adjustTarget = ref<{ ingredient: Ingredient | null; row: BranchStockRow | null }>({
    ingredient: null,
    row: null,
});
const adjustForm = reactive<{ signed_quantity: string; note: string; unit: string }>({
    signed_quantity: '',
    note: '',
    unit: '',
});

const restockOpen = ref(false);
const restockBusy = ref(false);
const restockError = ref<string | null>(null);
const restockErrors = ref<Record<string, string[]>>({});
const restockTarget = ref<{ ingredient: Ingredient | null; row: BranchStockRow | null }>({
    ingredient: null,
    row: null,
});

// =================== Phase A — purchase modal ====================
// Piece-aware purchase batch (Additions §2.4). Pieces and/or total
// units + the money paid; the unit cost is DERIVED (total ÷ units),
// never typed. A loose batch (pieces + units) rewrites the
// ingredient's units_per_piece — last batch wins.

const purchaseOpen = ref(false);
const purchaseBusy = ref(false);
const purchaseError = ref<string | null>(null);
const purchaseErrors = ref<Record<string, string[]>>({});
const purchaseTarget = ref<{ ingredient: Ingredient | null }>({ ingredient: null });
const purchaseForm = reactive<{
    pieces: string;
    units: string;
    total_paid: string;
    supplier_uuid: string;
    note: string;
}>({ pieces: '', units: '', total_paid: '', supplier_uuid: '', note: '' });

// =================== Phase A — day-end stock counts ==============
// (Additions §2.8.) The modal lists every ingredient stocked at the
// selected branch; staff fill the COUNTED column (pieces for piece-
// tracked ingredients, base units otherwise). Blank = not counted,
// skipped. Submit reconciles server-side (shortfall → waste with
// reason reconciliation_variance, overage → adjustment).

const stockCounts = ref<PaginatedStockCounts | null>(null);
const stockCountsPage = ref(1);
const expandedCountUuid = ref<string | null>(null);

const countOpen = ref(false);
const countBusy = ref(false);
const countError = ref<string | null>(null);
const countNote = ref('');
// LAUNCH-P2 P2-6 — BLIND: a row is an ingredient to count, never the
// system quantity.
interface CountRow {
    ingredient: Ingredient;
    counted: string;
    /**
     * LAUNCH item kind, A7 — what the amount is counted in: '' = the stored
     * unit, kg/l, a pack size, or '@piece' = the count container.
     */
    unit: string;
}
const countRows = ref<CountRow[]>([]);
// =================== Phase 5c modals ============================
// 5 new modals: record waste, create/edit restock request, show
// restock request, approve+reject (shared review modal), cancel,
// allocate. Each follows the same busy/error/errors pattern as
// the Phase 5a modals above.

const wasteOpen = ref(false);
const wasteBusy = ref(false);
const wasteError = ref<string | null>(null);
const wasteErrors = ref<Record<string, string[]>>({});
/** LAUNCH-P3 fix order 1, K3 — the last waste took a balance below zero (it was still recorded). */
const wasteWarning = ref<string | null>(null);
const wasteForm = reactive<{
    ingredient_uuid: string;
    quantity: string;
    reason: WasteReason;
    notes: string;
    occurred_at: string;
    unit: string;
}>({ ingredient_uuid: '', quantity: '', reason: 'spoiled', notes: '', occurred_at: '', unit: '' });

const restockModalOpen = ref(false);
const restockModalBusy = ref(false);
const restockModalError = ref<string | null>(null);
const restockModalErrors = ref<Record<string, string[]>>({});
const restockModalMode = ref<'create' | 'edit'>('create');
const restockModalTarget = ref<RestockRequest | null>(null);
const restockForm2 = reactive<{
    branch_uuid: string;
    note: string;
    lines: { ingredient_uuid: string; quantity: string; note: string; unit: string }[];
}>({ branch_uuid: '', note: '', lines: [] });

const showOpen = ref(false);
const showTarget = ref<RestockRequest | null>(null);

const reviewOpen = ref(false);
const reviewBusy = ref(false);
const reviewError = ref<string | null>(null);
const reviewMode = ref<'approve' | 'reject'>('approve');
const reviewTarget = ref<RestockRequest | null>(null);
const reviewNote = ref('');

const cancelOpen = ref(false);
const cancelBusy = ref(false);
const cancelError = ref<string | null>(null);
const cancelTarget = ref<RestockRequest | null>(null);
const cancelNote = ref('');

const allocateOpen = ref(false);
const allocateBusy = ref(false);
const allocateError = ref<string | null>(null);
const allocateTarget = ref<RestockRequest | null>(null);
// Map line.id (as string for v-model) → allocated quantity string.
const allocateOverrides = reactive<Record<string, string>>({});

// =================== Smart restock suggestions ==================
// Read-only forecast panel (inventory.view). Fetches per the
// currently-selected branch; each row is editable + includable,
// and the checked rows can be turned into a restock request
// (inventory.restock_request.create). Quantities stay STRINGS
// end-to-end — `qty` is the editable suggested amount.

interface SuggestionRow {
    suggestion: RestockSuggestion;
    include: boolean;
    qty: string;
    /** F5 — the unit qty is typed in ('' = the stored unit). */
    unit: string;
}

const suggestOpen = ref(false);
const suggestLoading = ref(false);
const suggestError = ref<string | null>(null);
const suggestCreating = ref(false);
// Re-fetch knobs — clamped 1..365 server-side; defaults 30 / 14.
const suggestWindowDays = ref(30);
const suggestCoverDays = ref(14);
const suggestRows = ref<SuggestionRow[]>([]);
// Set true once a fetch has resolved, so the empty-state only
// renders after a real "nothing to reorder" response.
const suggestLoaded = ref(false);
const suggestNote = ref('');

const suggestSelectedCount = computed<number>(() =>
    suggestRows.value.filter((r) => r.include && String(r.qty).trim() !== '').length,
);

// =================== Adjust/Restock modal form (Phase 5a) ========
// (Existing block kept below — only renamed-by-context, not by
// shape, to disambiguate from Phase 5c restockModalOpen above.)

const restockForm = reactive<{
    quantity: string;
    unit_cost: string;
    supplier_uuid: string;
    note: string;
    unit: string;
}>({ quantity: '', unit_cost: '', supplier_uuid: '', note: '', unit: '' });

// =================== Delete confirms =============================

const ingDeleteTarget = ref<Ingredient | null>(null);
const supDeleteTarget = ref<Supplier | null>(null);
const deleting = ref(false);

// =================== Movement filters ============================

const movementFilters = reactive<{
    ingredient_uuid: string;
    type: StockMovementType | '';
}>({ ingredient_uuid: '', type: '' });
const movementsPage = ref(1);

// =================== Fetchers ====================================

async function fetchBranches(): Promise<void> {
    try {
        const response = await listBranches();
        branches.value = response.data;
        if (selectedBranchUuid.value === null && branches.value.length > 0) {
            selectedBranchUuid.value = branches.value[0].uuid;
        }
    } catch {
        branches.value = [];
    }
}

async function fetchIngredients(): Promise<void> {
    try {
        const response = await listIngredients();
        ingredients.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load ingredients';
    }
}

/** LAUNCH-P3 P3-4 — prep items for the waste picker only (soft-fail). */
async function fetchPrepIngredients(): Promise<void> {
    try {
        prepIngredients.value = (await listIngredients({ includePrep: true })).data.filter((i) => i.is_prep);
    } catch {
        prepIngredients.value = [];
    }
}

async function fetchPhysicalItems(): Promise<void> {
    try {
        const response = await listPhysicalItems();
        physicalItems.value = response.data;
    } catch {
        physicalItems.value = [];
    }
}

function openCreatePhysicalItem(): void {
    physicalItemModalMode.value = 'create';
    physicalItemModalTarget.value = null;
    physicalItemForm.name = '';
    physicalItemForm.name_ar = '';
    physicalItemForm.purpose = 'packaging';
    physicalItemForm.cost_price = '';
    physicalItemForm.low_stock_threshold = '';
    physicalItemForm.status = 'active';
    physicalItemModalErrors.value = {};
    physicalItemModalError.value = null;
    physicalItemModalOpen.value = true;
}

function openEditPhysicalItem(item: PhysicalItem): void {
    physicalItemModalMode.value = 'edit';
    physicalItemModalTarget.value = item;
    physicalItemForm.name = item.name;
    physicalItemForm.name_ar = item.name_ar ?? '';
    physicalItemForm.purpose = item.purpose;
    physicalItemForm.cost_price = item.cost_price ?? '';
    physicalItemForm.low_stock_threshold = item.low_stock_threshold ?? '';
    physicalItemForm.status = (item.status ?? 'active') as 'active' | 'inactive';
    physicalItemModalErrors.value = {};
    physicalItemModalError.value = null;
    physicalItemModalOpen.value = true;
}

async function submitPhysicalItem(): Promise<void> {
    physicalItemModalBusy.value = true;
    physicalItemModalErrors.value = {};
    physicalItemModalError.value = null;
    try {
        const payload = {
            name: physicalItemForm.name.trim(),
            name_ar: physicalItemForm.name_ar.trim() || null,
            purpose: physicalItemForm.purpose,
            cost_price: String(physicalItemForm.cost_price ?? '').trim() === '' ? null : String(physicalItemForm.cost_price).trim(),
            low_stock_threshold: String(physicalItemForm.low_stock_threshold ?? '').trim() === '' ? null : String(physicalItemForm.low_stock_threshold).trim(),
        };
        if (physicalItemModalMode.value === 'create') {
            await createPhysicalItem(payload);
        } else if (physicalItemModalTarget.value) {
            await updatePhysicalItem(physicalItemModalTarget.value.uuid, { ...payload, status: physicalItemForm.status });
        }
        physicalItemModalOpen.value = false;
        await fetchPhysicalItems();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            physicalItemModalErrors.value = err.payload.errors;
            physicalItemModalError.value = t('inventory.physical_items.validation');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            const message = (err.payload as { message?: unknown }).message;
            physicalItemModalError.value = (typeof message === 'string' && message !== '') ? message : t('inventory.physical_items.save_failed');
        } else {
            physicalItemModalError.value = t('inventory.physical_items.save_failed');
        }
    } finally {
        physicalItemModalBusy.value = false;
    }
}

async function confirmDeletePhysicalItem(): Promise<void> {
    if (!physicalItemDeleteTarget.value) return;
    physicalItemDeleting.value = true;
    try {
        await deletePhysicalItem(physicalItemDeleteTarget.value.uuid);
        physicalItemDeleteTarget.value = null;
        await fetchPhysicalItems();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            const message = (err.payload as { message?: unknown }).message;
            error.value = (typeof message === 'string' && message !== '') ? message : t('inventory.physical_items.save_failed');
        } else {
            error.value = t('inventory.physical_items.save_failed');
        }
        physicalItemDeleteTarget.value = null;
    } finally {
        physicalItemDeleting.value = false;
    }
}

async function fetchSuppliers(): Promise<void> {
    try {
        const response = await listSuppliers();
        suppliers.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load suppliers';
    }
}

async function fetchBranchStock(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        branchStock.value = [];
        return;
    }
    try {
        const response = await listBranchStock(selectedBranchUuid.value, stockFilter.value === 'low' ? 'low' : null);
        branchStock.value = response.data;
        branchStockMeta.value = response.meta ?? null;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load stock';
    }
}

async function fetchMovements(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        movements.value = null;
        return;
    }
    try {
        movements.value = await listStockMovements(selectedBranchUuid.value, {
            ingredient: movementFilters.ingredient_uuid || undefined,
            type: movementFilters.type || undefined,
            page: movementsPage.value,
        });
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load movements';
    }
}

// Phase 5c — pull waste for the selected branch + restock
// requests across all branches in the tenant.
async function fetchWaste(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        waste.value = null;
        return;
    }
    try {
        waste.value = await listWaste(selectedBranchUuid.value, {
            ingredient: wasteFilters.ingredient_uuid || undefined,
            reason: wasteFilters.reason || undefined,
            page: wastePage.value,
        });
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load waste records';
    }
}

async function fetchStockCounts(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        stockCounts.value = null;
        return;
    }
    try {
        stockCounts.value = await listStockCounts(selectedBranchUuid.value, {
            page: stockCountsPage.value,
        });
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load stock counts';
    }
}

async function fetchRestockRequests(): Promise<void> {
    try {
        const response = await listRestockRequests({
            status: restockFilters.status || undefined,
            branch: restockFilters.branch_uuid || undefined,
        });
        restockRequests.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load restock requests';
    }
}

async function fetchBranchTransfers(): Promise<void> {
    try {
        const response = await listBranchTransfers(transferFilters.branch_uuid || undefined);
        branchTransfers.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load transfers';
    }
}

async function fetchInventorySettings(): Promise<void> {
    try {
        singleStockIn.value = (await getInventorySettings()).data.single_stock_in;
    } catch {
        singleStockIn.value = true;
    }
}

/** LAUNCH-P2 — deep links (the dashboard's Low stock card): ?tab=stock&filter=low&branch=uuid. */
function applyRouteQuery(): void {
    const tab = String(route.query.tab ?? '');
    if (tab === 'stock' || tab === 'stock_counts' || tab === 'movements' || tab === 'prep_items') {
        activeTab.value = tab;
    }
    if (route.query.filter === 'low') {
        stockFilter.value = 'low';
    }
    const branch = String(route.query.branch ?? '');
    if (branch !== '' && branches.value.some((b) => b.uuid === branch)) {
        selectedBranchUuid.value = branch;
    }
}

async function bootstrap(): Promise<void> {
    loading.value = true;
    error.value = null;
    // Restock requests aren't branch-scoped on the API — load
    // them eagerly so the count badge on the tab is accurate
    // even before the user clicks the tab.
    await Promise.all([fetchBranches(), fetchIngredients(), fetchPrepIngredients(), fetchPhysicalItems(), fetchSuppliers(), fetchRestockRequests(), fetchBranchTransfers(), fetchInventorySettings()]);
    applyRouteQuery();
    if (selectedBranchUuid.value !== null) {
        await Promise.all([fetchBranchStock(), fetchMovements(), fetchWaste(), fetchStockCounts()]);
    }
    loading.value = false;
}

onMounted(() => {
    void bootstrap();
});

// Re-fetch stock + movements + waste + counts when the branch picker changes.
watch(selectedBranchUuid, () => {
    void fetchBranchStock();
    void fetchMovements();
    void fetchWaste();
    stockCountsPage.value = 1;
    void fetchStockCounts();
});

// Phase A — stock-count pagination.
watch(stockCountsPage, () => void fetchStockCounts());

// LAUNCH-P2 P2-7 — the Low stock filter.
watch(stockFilter, () => void fetchBranchStock());

// Re-fetch movements when filters change.
watch(
    () => [movementFilters.ingredient_uuid, movementFilters.type, movementsPage.value],
    () => void fetchMovements(),
);

// Phase 5c — re-fetch waste + restock requests when their filters
// change. wastePage is part of the watch so pagination clicks
// trigger a fetch.
watch(
    () => [wasteFilters.ingredient_uuid, wasteFilters.reason, wastePage.value],
    () => void fetchWaste(),
);
watch(
    () => [restockFilters.status, restockFilters.branch_uuid],
    () => void fetchRestockRequests(),
);
watch(
    () => transferFilters.branch_uuid,
    () => void fetchBranchTransfers(),
);

// =================== Ingredient flows ============================

function openCreateIngredient(): void {
    ingModalMode.value = 'create';
    ingModalTarget.value = null;
    ingForm.name = '';
    ingForm.name_ar = '';
    // No kind until the person picks one: milk must never be saved as Weighed by default.
    ingForm.unit = '';
    ingForm.piece_unit_label = '';
    ingForm.piece_unit_label_ar = '';
    ingForm.container_amount = '';
    ingForm.container_unit = '';
    ingForm.allow_fractional_pieces = true;
    ingForm.default_unit_cost = '0.000';
    ingForm.cost_unit = '';
    ingForm.min_stock_threshold = '';
    ingForm.min_stock_unit = '';
    ingForm.primary_supplier_id = null;
    ingForm.status = 'active';
    ingModalErrors.value = {};
    ingModalError.value = null;
    resetAltUnits();
    packSizeDrafts.value = [];
    ingModalOpen.value = true;
}

function openEditIngredient(ingredient: Ingredient): void {
    ingModalMode.value = 'edit';
    ingModalTarget.value = ingredient;
    ingForm.name = ingredient.name;
    ingForm.name_ar = ingredient.name_ar ?? '';
    ingForm.unit = ingredient.unit;
    ingForm.piece_unit_label = ingredient.piece_unit_label ?? '';
    ingForm.piece_unit_label_ar = ingredient.piece_unit_label_ar ?? '';
    // A5 — "1500.0000" ml reopens as "bottle holds 1.5 l".
    const holds = holdsEntry(ingredient.units_per_piece, ingredient.unit);
    ingForm.container_amount = holds.amount;
    ingForm.container_unit = holds.amount !== '' ? holds.unit : (kindUnits(ingredient.unit)[0]?.value ?? '');
    ingForm.allow_fractional_pieces = ingredient.allow_fractional_pieces;
    // F1 — 0.00015 per ml reopens as 0.150 per l.
    ingForm.default_unit_cost = friendlyCost(ingredient.default_unit_cost, ingredient.unit).amount;
    ingForm.cost_unit = costUnit(ingredient.unit);
    // A7 — a 5000 g minimum reopens as 5 kg (exactly, or in the stored unit).
    const minimum = holdsEntry(ingredient.min_stock_threshold, ingredient.unit);
    ingForm.min_stock_threshold = ingredient.min_stock_threshold === null ? '' : (minimum.amount === '' ? trimAmount(parseFloat(ingredient.min_stock_threshold)) : minimum.amount);
    ingForm.min_stock_unit = minimum.amount === '' ? ingredient.unit : minimum.unit;
    ingForm.primary_supplier_id = ingredient.primary_supplier_id;
    ingForm.status = ingredient.status;
    ingModalErrors.value = {};
    ingModalError.value = null;
    // Seed alt units from the eager-loaded array, then refresh from
    // the API so the editor always reflects server truth.
    seedAltUnits(ingredient.alt_units ?? []);
    void loadAltUnits(ingredient.uuid);
    ingModalOpen.value = true;
}

async function submitIngredient(): Promise<void> {
    ingModalErrors.value = {};
    ingModalError.value = null;
    if (ingForm.unit === '') {
        ingModalErrors.value = { unit: [t('item_kind.choose')] };
        ingModalError.value = t('inventory.validation_summary');
        return;
    }
    if (kindChangeBlocked.value) {
        ingModalErrors.value = { unit: [t('item_kind.kind_change_blocked')] };
        ingModalError.value = t('inventory.validation_summary');
        return;
    }
    const unit: IngredientUnit = ingForm.unit;
    ingModalBusy.value = true;
    try {
        const payload = {
            name: ingForm.name.trim(),
            name_ar: ingForm.name_ar.trim() || null,
            unit,
            // Phase A — piece config travels as a pair (server enforces
            // both-or-neither); blanks become null = "not piece-tracked".
            piece_unit_label: ingForm.piece_unit_label.trim() || null,
            piece_unit_label_ar: ingForm.piece_unit_label_ar.trim() || null,
            // A5 — "bottle holds 1.5 l" → 1500 (ml).
            units_per_piece: containerUnitsPerPiece(unit),
            allow_fractional_pieces: ingForm.allow_fractional_pieces,
            // F1 — typed per kg / l, sent per stored unit (6 decimals).
            default_unit_cost: costInStoredUnit(unit),
            // The bound input is type="number", so Vue casts this to a
            // number as soon as the user types — String() keeps the
            // empty-check safe for both the number and blank-string cases.
            // A7 — typed in a unit of the kind, sent in the stored unit.
            min_stock_threshold: minimumInStoredUnit(unit),
            primary_supplier_id: ingForm.primary_supplier_id ?? null,
        };
        if (ingModalMode.value === 'create') {
            // A3 — the pack sizes ride the same request (one transaction).
            const packSizes = packSizesPayload();
            await createIngredient(packSizes.length > 0 ? { ...payload, pack_sizes: packSizes } : payload);
        } else if (ingModalTarget.value) {
            await updateIngredient(ingModalTarget.value.uuid, {
                ...payload,
                status: ingForm.status,
            });
        }
        ingModalOpen.value = false;
        await fetchIngredients();
        // Refresh stock too — supplier names may have changed
        // and the stock list inlines them.
        await fetchBranchStock();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            ingModalErrors.value = err.payload.errors;
            ingModalError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            ingModalError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            ingModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        ingModalBusy.value = false;
    }
}

async function confirmDeleteIngredient(): Promise<void> {
    if (!ingDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteIngredient(ingDeleteTarget.value.uuid);
        ingDeleteTarget.value = null;
        await fetchIngredients();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            error.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            error.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        deleting.value = false;
    }
}

// =================== Alternate-unit flows (v2 #13) ===============

function resetAltUnits(): void {
    altUnits.value = [];
    altUnitsError.value = null;
    altUnitFieldErrors.value = {};
    altUnitBusyUuid.value = null;
    altUnitNew.name = '';
    altUnitNew.name_ar = '';
    altUnitNew.amount = '';
    altUnitNew.unit = savedHoldUnits.value[0]?.value ?? '';
    altUnitNewBusy.value = false;
    for (const k of Object.keys(altUnitDrafts)) delete altUnitDrafts[k];
}

function seedAltUnits(units: IngredientAltUnit[]): void {
    resetAltUnits();
    altUnits.value = [...units].sort((a, b) => a.sort_order - b.sort_order);
    syncAltUnitDrafts();
}

// Mirror the source list into editable drafts (what it holds + Arabic
// name), so editing a row doesn't mutate the canonical data. A4 — a
// factor of 12000 on a ml item reopens as "holds 12 l".
function syncAltUnitDrafts(): void {
    for (const k of Object.keys(altUnitDrafts)) delete altUnitDrafts[k];
    for (const u of altUnits.value) {
        const holds = holdsEntry(u.factor, ingModalTarget.value?.unit);
        altUnitDrafts[u.uuid] = { name_ar: u.name_ar ?? '', amount: holds.amount, unit: holds.unit };
    }
}

/** A4 — the first field error of a pack row ('' = the add-new row). */
function altUnitError(uuid: string): string | null {
    const errors = altUnitFieldErrors.value[uuid] ?? {};
    for (const field of ['name', 'amount', 'unit', 'factor']) {
        const messages = errors[field];
        if (messages && messages.length > 0) return messages[0]!;
    }
    return null;
}

/** A4 — a saved pack size as people read it: "holds 12 l". */
function packHoldsText(unit: IngredientAltUnit): string {
    const holds = friendlyAmount(unit.factor, ingModalTarget.value?.unit);
    return t('item_kind.holds_amount', { amount: `${holds.amount} ${holdUnitLabel(holds.unit)}` });
}

async function loadAltUnits(ingredientUuid: string): Promise<void> {
    altUnitsLoading.value = true;
    altUnitsError.value = null;
    try {
        const response = await listIngredientUnits(ingredientUuid);
        altUnits.value = [...response.data].sort((a, b) => a.sort_order - b.sort_order);
        syncAltUnitDrafts();
    } catch (err) {
        altUnitsError.value =
            err instanceof Error ? err.message : t('item_kind.pack_sizes.errors.load_failed');
    } finally {
        altUnitsLoading.value = false;
    }
}

function altUnitErrorMessage(err: unknown, fallbackKey: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        return String((err.payload as { message?: unknown }).message ?? t(fallbackKey));
    }
    return err instanceof Error ? err.message : t(fallbackKey);
}

async function addAltUnit(): Promise<void> {
    if (!ingModalTarget.value) return;
    altUnitNewBusy.value = true;
    altUnitsError.value = null;
    altUnitFieldErrors.value = { ...altUnitFieldErrors.value, '': {} };
    try {
        await createIngredientUnit(ingModalTarget.value.uuid, {
            name: altUnitNew.name.trim(),
            name_ar: altUnitNew.name_ar.trim() || null,
            // A4 — what the pack holds; the server works out the factor.
            amount: String(altUnitNew.amount).trim(),
            unit: altUnitNew.unit,
        });
        altUnitNew.name = '';
        altUnitNew.name_ar = '';
        altUnitNew.amount = '';
        await loadAltUnits(ingModalTarget.value.uuid);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            altUnitFieldErrors.value = { ...altUnitFieldErrors.value, '': err.payload.errors };
            altUnitsError.value = t('inventory.validation_summary');
        } else {
            altUnitsError.value = altUnitErrorMessage(err, 'item_kind.pack_sizes.errors.save_failed');
        }
    } finally {
        altUnitNewBusy.value = false;
    }
}

async function saveAltUnit(unit: IngredientAltUnit): Promise<void> {
    if (!ingModalTarget.value) return;
    altUnitBusyUuid.value = unit.uuid;
    altUnitsError.value = null;
    altUnitFieldErrors.value = { ...altUnitFieldErrors.value, [unit.uuid]: {} };
    const draft = altUnitDrafts[unit.uuid];
    try {
        // name is IMMUTABLE — only what it holds + Arabic name go up.
        await updateIngredientUnit(ingModalTarget.value.uuid, unit.uuid, {
            name_ar: draft.name_ar.trim() || null,
            amount: String(draft.amount).trim(),
            unit: draft.unit,
        });
        await loadAltUnits(ingModalTarget.value.uuid);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            altUnitFieldErrors.value = {
                ...altUnitFieldErrors.value,
                [unit.uuid]: err.payload.errors,
            };
            altUnitsError.value = t('inventory.validation_summary');
        } else {
            altUnitsError.value = altUnitErrorMessage(err, 'item_kind.pack_sizes.errors.save_failed');
        }
    } finally {
        altUnitBusyUuid.value = null;
    }
}

async function removeAltUnit(unit: IngredientAltUnit): Promise<void> {
    if (!ingModalTarget.value) return;
    if (!window.confirm(t('item_kind.pack_sizes.delete_confirm'))) return;
    altUnitBusyUuid.value = unit.uuid;
    altUnitsError.value = null;
    try {
        await deleteIngredientUnit(ingModalTarget.value.uuid, unit.uuid);
        await loadAltUnits(ingModalTarget.value.uuid);
    } catch (err) {
        altUnitsError.value = altUnitErrorMessage(err, 'item_kind.pack_sizes.errors.delete_failed');
    } finally {
        altUnitBusyUuid.value = null;
    }
}

// =================== Supplier flows ==============================

function openCreateSupplier(): void {
    supModalMode.value = 'create';
    supModalTarget.value = null;
    supForm.name = '';
    supForm.contact = '';
    supForm.notes = '';
    supForm.status = 'active';
    supModalErrors.value = {};
    supModalError.value = null;
    supModalOpen.value = true;
}

function openEditSupplier(supplier: Supplier): void {
    supModalMode.value = 'edit';
    supModalTarget.value = supplier;
    supForm.name = supplier.name;
    supForm.contact = supplier.contact ?? '';
    supForm.notes = supplier.notes ?? '';
    supForm.status = supplier.status;
    supModalErrors.value = {};
    supModalError.value = null;
    supModalOpen.value = true;
}

async function submitSupplier(): Promise<void> {
    supModalBusy.value = true;
    supModalErrors.value = {};
    supModalError.value = null;
    try {
        const payload = {
            name: supForm.name.trim(),
            contact: supForm.contact.trim() || null,
            notes: supForm.notes.trim() || null,
        };
        if (supModalMode.value === 'create') {
            await createSupplier(payload);
        } else if (supModalTarget.value) {
            await updateSupplier(supModalTarget.value.uuid, { ...payload, status: supForm.status });
        }
        supModalOpen.value = false;
        await fetchSuppliers();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            supModalErrors.value = err.payload.errors;
            supModalError.value = t('inventory.validation_summary');
        } else {
            supModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        supModalBusy.value = false;
    }
}

async function confirmDeleteSupplier(): Promise<void> {
    if (!supDeleteTarget.value) return;
    deleting.value = true;
    try {
        await deleteSupplier(supDeleteTarget.value.uuid);
        supDeleteTarget.value = null;
        await fetchSuppliers();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            error.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            error.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        deleting.value = false;
    }
}

// =================== Adjust / Restock flows ======================

function openAdjust(row: BranchStockRow): void {
    const ingredient = ingredients.value.find((i) => i.id === row.ingredient_id) ?? null;
    adjustTarget.value = { ingredient, row };
    adjustForm.signed_quantity = '';
    adjustForm.note = '';
    adjustForm.unit = '';
    adjustErrors.value = {};
    adjustError.value = null;
    adjustOpen.value = true;
}

async function submitAdjust(): Promise<void> {
    if (selectedBranchUuid.value === null || adjustTarget.value.ingredient === null) return;
    adjustBusy.value = true;
    adjustErrors.value = {};
    adjustError.value = null;
    try {
        await adjustStock(selectedBranchUuid.value, {
            ingredient_uuid: adjustTarget.value.ingredient.uuid,
            signed_quantity: adjustForm.signed_quantity,
            note: adjustForm.note,
            unit: wireUnit(adjustForm.unit),
        });
        adjustOpen.value = false;
        await Promise.all([fetchBranchStock(), fetchMovements()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            adjustErrors.value = err.payload.errors;
            adjustError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            adjustError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            adjustError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        adjustBusy.value = false;
    }
}

function openRestock(row: BranchStockRow | null, ingredient?: Ingredient): void {
    const ing = ingredient ?? (row ? ingredients.value.find((i) => i.id === row.ingredient_id) ?? null : null);
    restockTarget.value = { ingredient: ing, row };
    restockForm.quantity = '';
    restockForm.unit_cost = '';
    restockForm.supplier_uuid = '';
    restockForm.note = '';
    restockForm.unit = '';
    restockErrors.value = {};
    restockError.value = null;
    restockOpen.value = true;
}

// Reset the entry unit to base whenever the picked ingredient
// changes (the modal lets the user re-pick the ingredient), so a
// stale alt-unit name from a different ingredient can't be sent.
watch(
    () => restockTarget.value.ingredient?.uuid,
    () => {
        restockForm.unit = '';
    },
);

async function submitRestock(): Promise<void> {
    if (selectedBranchUuid.value === null || restockTarget.value.ingredient === null) return;
    restockBusy.value = true;
    restockErrors.value = {};
    restockError.value = null;
    try {
        await restockStock(selectedBranchUuid.value, {
            ingredient_uuid: restockTarget.value.ingredient.uuid,
            quantity: restockForm.quantity,
            // type="number" input -> Vue casts to a number; String() keeps
            // the empty-check safe (same fix as the ingredient threshold).
            unit_cost: String(restockForm.unit_cost).trim() === '' ? null : restockForm.unit_cost,
            supplier_uuid: restockForm.supplier_uuid || null,
            note: restockForm.note.trim() || null,
            unit: wireUnit(restockForm.unit),
        });
        restockOpen.value = false;
        await Promise.all([fetchBranchStock(), fetchMovements()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            restockErrors.value = err.payload.errors;
            restockError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            restockError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            restockError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        restockBusy.value = false;
    }
}

// =================== Phase A — purchase flow =====================

function openPurchase(row: BranchStockRow | null, ingredient?: Ingredient): void {
    const ing = ingredient ?? (row ? ingredients.value.find((i) => i.id === row.ingredient_id) ?? null : null);
    purchaseTarget.value = { ingredient: ing };
    purchaseForm.pieces = '';
    purchaseForm.units = '';
    purchaseForm.total_paid = '';
    purchaseForm.supplier_uuid = ing?.primary_supplier
        ? suppliers.value.find((s) => s.id === ing.primary_supplier_id)?.uuid ?? ''
        : '';
    purchaseForm.note = '';
    purchaseErrors.value = {};
    purchaseError.value = null;
    purchaseOpen.value = true;
}

/**
 * The piece label staff physically count in for an ingredient —
 * the configured label (AR-aware), the base unit when it is itself
 * 'piece', or null when the ingredient is not piece-tracked.
 */
function pieceLabelFor(ing: Ingredient | null): string | null {
    if (!ing) return null;
    if (ing.piece_unit_label && ing.units_per_piece) {
        return isArabic.value && ing.piece_unit_label_ar ? ing.piece_unit_label_ar : ing.piece_unit_label;
    }
    return ing.unit === 'piece' ? unitLabel('piece') : null;
}

/** Local derived preview: total base units + unit cost of the batch. */
const purchasePreview = computed<{ units: number | null; unitCost: number | null }>(() => {
    const ing = purchaseTarget.value.ingredient;
    if (!ing) return { units: null, unitCost: null };
    const pieces = String(purchaseForm.pieces).trim() === '' ? null : Number(purchaseForm.pieces);
    const unitsIn = String(purchaseForm.units).trim() === '' ? null : Number(purchaseForm.units);
    let units: number | null = null;
    if (unitsIn !== null && Number.isFinite(unitsIn) && unitsIn > 0) {
        units = unitsIn;
    } else if (pieces !== null && Number.isFinite(pieces) && pieces > 0) {
        const ratio = ing.piece_unit_label && ing.units_per_piece
            ? Number(ing.units_per_piece)
            : ing.unit === 'piece' ? 1 : null;
        units = ratio !== null && Number.isFinite(ratio) && ratio > 0 ? pieces * ratio : null;
    }
    const paid = Number(purchaseForm.total_paid);
    const unitCost = units !== null && units > 0 && Number.isFinite(paid) && paid > 0 ? paid / units : null;
    return { units, unitCost };
});

async function submitPurchase(): Promise<void> {
    if (selectedBranchUuid.value === null || purchaseTarget.value.ingredient === null) return;
    purchaseBusy.value = true;
    purchaseErrors.value = {};
    purchaseError.value = null;
    try {
        await recordPurchase(selectedBranchUuid.value, {
            ingredient_uuid: purchaseTarget.value.ingredient.uuid,
            pieces: String(purchaseForm.pieces).trim() === '' ? null : purchaseForm.pieces,
            units: String(purchaseForm.units).trim() === '' ? null : purchaseForm.units,
            total_paid: purchaseForm.total_paid,
            supplier_uuid: purchaseForm.supplier_uuid || null,
            note: purchaseForm.note.trim() || null,
        });
        purchaseOpen.value = false;
        success.value = t('inventory.purchase_modal.success');
        // A purchase can change the ingredient's ratio + default cost.
        await Promise.all([fetchBranchStock(), fetchMovements(), fetchIngredients()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            purchaseErrors.value = err.payload.errors;
            purchaseError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            purchaseError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            purchaseError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        purchaseBusy.value = false;
    }
}

// =================== Phase A — day-end count flow ================

function openCount(): void {
    // LAUNCH-P2 P2-6 — every active ingredient (not only those with a
    // stock row), and never the quantity on the books.
    countRows.value = ingredients.value
        .filter((ingredient) => ingredient.status === 'active')
        .map((ingredient) => ({ ingredient, counted: '', unit: defaultCountUnit(ingredient) }));
    countNote.value = '';
    countError.value = null;
    countOpen.value = true;
}

/**
 * A7 — a row starts in the count container when the ingredient has one
 * (staff count bottles, as before), else in the stored unit; the person can
 * pick any unit the ingredient knows.
 */
function defaultCountUnit(ingredient: Ingredient): string {
    return ingredientUnitOptions(ingredient).some((u) => u.value === PIECE_UNIT) ? PIECE_UNIT : '';
}

/** Whether a row is counted in whole pieces (the container, or a piece-stored item in pieces). */
function countsPieces(r: CountRow): boolean {
    return r.unit === PIECE_UNIT || (r.unit === '' && r.ingredient.unit === 'piece');
}

/**
 * A7 — one count line: containers (and a piece-stored item counted in
 * pieces) go as counted_pieces, as before; any other unit goes as
 * counted_units + the unit, converted by the server (2.5 l → 2500 ml).
 */
function countLinePayload(r: CountRow): StockCountLinePayload {
    if (countsPieces(r)) return { ingredient_uuid: r.ingredient.uuid, counted_pieces: r.counted };
    return r.unit === ''
        ? { ingredient_uuid: r.ingredient.uuid, counted_units: r.counted }
        : { ingredient_uuid: r.ingredient.uuid, counted_units: r.counted, unit: r.unit };
}

const countFilledRows = computed<number>(() =>
    countRows.value.filter((r) => String(r.counted).trim() !== '').length,
);

async function submitCount(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    const lines: StockCountLinePayload[] = [];
    for (const r of countRows.value) {
        if (String(r.counted).trim() === '') continue;
        lines.push(countLinePayload(r));
    }
    if (lines.length === 0) {
        countError.value = t('inventory.counts.modal.empty_error');
        return;
    }
    countBusy.value = true;
    countError.value = null;
    try {
        const response = await submitStockCount(selectedBranchUuid.value, {
            lines,
            note: countNote.value.trim() || null,
        });
        countOpen.value = false;
        // P2-6 — the variance shows only after submit, and only to users
        // who may see stock values.
        const varianceLines = response.data.lines.filter((l) => l.variance_units !== undefined && Number(l.variance_units) !== 0).length;
        success.value = response.data.shows_stock_values === false
            ? t('inventory.counts.success_blind', { lines: response.data.lines.length })
            : varianceLines > 0
                ? t('inventory.counts.success_with_variance', { lines: response.data.lines.length, variance: varianceLines })
                : t('inventory.counts.success_clean', { lines: response.data.lines.length });
        stockCountsPage.value = 1;
        await Promise.all([fetchBranchStock(), fetchMovements(), fetchWaste(), fetchStockCounts()]);
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            countError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            countError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        countBusy.value = false;
    }
}

/** Sum of a count's negative variance value (the shortfall cost), for the list row. */
function countShortfallValue(count: { lines: { variance_value?: string }[] }): number {
    return count.lines.reduce((sum, l) => {
        const v = Number(l.variance_value);
        return Number.isFinite(v) && v < 0 ? sum + v : sum;
    }, 0);
}

// =================== Helpers =====================================

function unitLabel(unit: IngredientUnit | '' | null): string {
    if (!unit) return '';
    return t(`inventory.units.${unit}`);
}

/**
 * LAUNCH item kind, A8 — an amount as people read it on the inventory
 * screens: 1000 g or ml and above in kg or l ("24 l", not "24000.000 ml"),
 * up to 4 decimals with trailing zeros trimmed.
 */
function qty(quantity: string | number | null | undefined, unit: string | null | undefined): string {
    const friendly = friendlyAmount(quantity, unit);
    return friendly.unit === '' ? friendly.amount : `${friendly.amount} ${friendly.unit}`;
}

function unitShort(unit: IngredientUnit | null): string {
    if (!unit) return '';
    // Just the symbol for column displays — full label only in dropdowns.
    return unit;
}

// =================== v2 #13 — entry-unit helpers =================
// Each ingredient has a base unit (`ingredient.unit`, factor 1)
// plus optional `alt_units` (each { name, factor } = base units per
// 1 of itself). When entering a quantity the user picks the base
// unit ('' value) or an alt unit (its NAME). The wire field `unit`
// carries that name, or null/omit for the base unit. Conversion to
// base = entered × factor (×1 for base) — used by the waste preview
// + warning so they stay correct in base units. Quantities are kept
// as STRINGS over the wire (no float round-trip); the conversion
// below parses ONLY for the local numeric preview, never to rebuild
// the value that gets sent.

/** Map a selected unit string to the wire value: '' → null, else the name. */
function wireUnit(selected: string): string | null {
    return selected.trim() === '' ? null : selected;
}

/**
 * Convert an entered quantity (number) to base units for the given
 * ingredient, given the selected alt-unit NAME ('' = base). Returns
 * the raw number unchanged when no matching alt unit is found.
 */
function toBaseUnits(qty: number, ingredient: Ingredient | null | undefined, selected: string): number {
    // PD4 — base + custom alt + auto metric sibling, resolved by the shared
    // helper; unknown unit = factor 1 (the server re-validates).
    return qty * ingredientUnitFactor(ingredient, selected);
}

function statusBadgeClass(status: string | null): string {
    return status === 'active'
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-200 text-slate-700';
}

function statusLabel(status: string | null): string {
    if (!status) return '—';
    return t(`inventory.statuses.${status}`);
}

function healthBadgeClass(level: string): string {
    if (level === 'critical') return 'bg-rose-100 text-rose-700';
    if (level === 'low') return 'bg-amber-100 text-amber-700';
    return 'bg-emerald-100 text-emerald-700';
}

function healthLabel(level: string): string {
    return t(`inventory.health.${level}`);
}

/** LAUNCH-P2 P2-7 — sell, but warn: negative red, below minimum amber. */
function stockStatusBadgeClass(status: string): string {
    if (status === 'negative') return 'bg-rose-100 text-rose-700';
    if (status === 'below_minimum') return 'bg-amber-100 text-amber-700';
    return 'bg-emerald-100 text-emerald-700';
}

function stockQuantityClass(status: string): string {
    if (status === 'negative') return 'text-rose-600';
    if (status === 'below_minimum') return 'text-amber-600';
    return 'text-slate-950';
}

function stockRowClass(status: string): string {
    if (status === 'negative') return 'bg-rose-50/60';
    if (status === 'below_minimum') return 'bg-amber-50/60';
    return '';
}

function movementTypeLabel(type: string): string {
    return t(`inventory.movement_types.${type}`);
}

// P-G4 — central warehouse dialog (company pool + Receive & Distribute).
const warehouseDialogIngredient = ref<Ingredient | null>(null);
function openWarehouseDialog(ing: Ingredient): void {
    warehouseDialogIngredient.value = ing;
}

function supplierName(id: number | null): string {
    if (id === null) return t('inventory.fields.primary_supplier_none');
    const match = suppliers.value.find((s) => s.id === id);
    return match?.name ?? '—';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(isArabic.value ? 'ar-OM' : 'en-GB', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function isOutflow(qty: string): boolean {
    return parseFloat(qty) < 0;
}

// =================== Phase 5c — Waste flows ======================

const selectedBranchName = computed<string>(() => {
    const branch = branches.value.find((b) => b.uuid === selectedBranchUuid.value);
    return branch?.name ?? '—';
});

// Live balance of the currently-picked ingredient at the
// currently-picked branch — drives the insufficient-stock warning
// in the Record Waste modal.
const wasteCurrentBalance = computed<string>(() => {
    if (!wasteForm.ingredient_uuid) return '—';
    // A prep item holds no stock: its raw ingredients are checked by the server.
    if (wasteIsPrep.value) return '—';
    const ing = ingredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid);
    if (!ing) return '—';
    const row = branchStock.value.find((r) => r.ingredient_id === ing.id);
    return row?.quantity ?? '0.000';
});

const wasteInsufficient = computed<boolean>(() => {
    if (wasteIsPrep.value) return false;
    const balance = parseFloat(wasteCurrentBalance.value);
    // The balance is in BASE units — convert the entered amount (which
    // may be in an alt unit) to base before comparing.
    const qty = toBaseUnits(parseFloat(wasteForm.quantity || '0'), wasteIngredient.value, wasteForm.unit);
    if (!Number.isFinite(balance) || !Number.isFinite(qty)) return false;
    return qty > 0 && qty > balance;
});

const wasteCostPreview = computed<string>(() => {
    const ing = wasteIngredient.value;
    if (!ing) return '0.000';
    // default_unit_cost is per BASE unit — convert the entered amount
    // to base units first so the preview stays correct for alt units.
    const baseQty = toBaseUnits(parseFloat(wasteForm.quantity || '0'), ing, wasteForm.unit);
    const cost = parseFloat(ing.default_unit_cost) * baseQty;
    if (!Number.isFinite(cost)) return '0.000';
    return cost.toFixed(3);
});

const wasteReasons: WasteReason[] = ['expired', 'spoiled', 'broken', 'dropped', 'contamination', 'other'];

function openRecordWaste(): void {
    wasteForm.ingredient_uuid = '';
    wasteForm.quantity = '';
    wasteForm.reason = 'spoiled';
    wasteForm.notes = '';
    wasteForm.occurred_at = '';
    wasteForm.unit = '';
    wasteErrors.value = {};
    wasteError.value = null;
    wasteOpen.value = true;
}

// Reset the entry unit to base when the picked ingredient changes,
// so a stale alt-unit name from a different ingredient can't ride
// the submit (and so the balance/cost previews recompute cleanly).
watch(
    () => wasteForm.ingredient_uuid,
    () => {
        wasteForm.unit = '';
    },
);

// The currently-picked waste ingredient (full object, for alt_units).
const wasteIngredient = computed<Ingredient | null>(() => {
    if (!wasteForm.ingredient_uuid) return null;
    return ingredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid)
        ?? prepIngredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid)
        ?? null;
});

/** LAUNCH-P3 P3-4 — the picked waste item is a prep item (its raw ingredients are wasted). */
const wasteIsPrep = computed<boolean>(() => wasteIngredient.value?.is_prep === true);

async function submitRecordWaste(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    wasteBusy.value = true;
    wasteErrors.value = {};
    wasteError.value = null;
    wasteWarning.value = null;
    try {
        const response = await recordWaste(selectedBranchUuid.value, {
            ingredient_uuid: wasteForm.ingredient_uuid,
            quantity: wasteForm.quantity,
            reason: wasteForm.reason,
            notes: wasteForm.notes.trim() || null,
            occurred_at: wasteForm.occurred_at.trim() || null,
            unit: wireUnit(wasteForm.unit),
        });
        // LAUNCH-P3 fix order 1, K3 — never refused on stock numbers: the
        // server warns when the waste took a balance below zero.
        wasteWarning.value = response.warning ?? null;
        wasteOpen.value = false;
        // Three things change on waste: the waste list, the
        // branch stock balance (decremented), and the movement
        // ledger (a new signed-negative row).
        await Promise.all([fetchWaste(), fetchBranchStock(), fetchMovements()]);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            wasteErrors.value = err.payload.errors;
            wasteError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            wasteError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            wasteError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        wasteBusy.value = false;
    }
}

// =================== Phase 5c — Restock-request flows ============
//
// Renamed from the obvious `statusBadgeClass` to avoid colliding
// with the Phase 5a helper of that name (which serves the
// ingredient + supplier active/inactive badges). Different
// domain, different colour palette — same shape signature would
// have been a footgun.

function restockStatusBadgeClass(status: RestockRequestStatus): string {
    switch (status) {
        case 'draft':
            return 'bg-slate-100 text-slate-700';
        case 'submitted':
            return 'bg-amber-100 text-amber-800';
        case 'approved':
            return 'bg-indigo-100 text-indigo-800';
        case 'fulfilled':
            return 'bg-emerald-100 text-emerald-800';
        case 'rejected':
            return 'bg-rose-100 text-rose-800';
        case 'cancelled':
            return 'bg-slate-200 text-slate-600';
    }
}

const restockStatuses: RestockRequestStatus[] = [
    'draft',
    'submitted',
    'approved',
    'fulfilled',
    'rejected',
    'cancelled',
];

const restockHasDuplicates = computed<boolean>(() => {
    const seen = new Set<string>();
    for (const line of restockForm2.lines) {
        if (!line.ingredient_uuid) continue;
        if (seen.has(line.ingredient_uuid)) return true;
        seen.add(line.ingredient_uuid);
    }
    return false;
});

function openCreateRestock(): void {
    restockModalMode.value = 'create';
    restockModalTarget.value = null;
    restockForm2.branch_uuid = selectedBranchUuid.value ?? (branches.value[0]?.uuid ?? '');
    restockForm2.note = '';
    restockForm2.lines = [{ ingredient_uuid: '', quantity: '', note: '', unit: '' }];
    restockModalErrors.value = {};
    restockModalError.value = null;
    restockModalOpen.value = true;
}

function openEditRestock(req: RestockRequest): void {
    restockModalMode.value = 'edit';
    restockModalTarget.value = req;
    restockForm2.branch_uuid = req.branch?.uuid ?? '';
    restockForm2.note = req.note ?? '';
    // Stored request lines hold quantity in BASE units already, so
    // preload the entry unit as base ('').
    restockForm2.lines = (req.lines ?? []).map((l) => ({
        ingredient_uuid: l.ingredient?.uuid ?? '',
        quantity: l.quantity_requested,
        note: l.note ?? '',
        unit: '',
    }));
    if (restockForm2.lines.length === 0) {
        restockForm2.lines = [{ ingredient_uuid: '', quantity: '', note: '', unit: '' }];
    }
    restockModalErrors.value = {};
    restockModalError.value = null;
    restockModalOpen.value = true;
}

function addRestockLine(): void {
    restockForm2.lines.push({ ingredient_uuid: '', quantity: '', note: '', unit: '' });
}

function removeRestockLine(idx: number): void {
    restockForm2.lines.splice(idx, 1);
    if (restockForm2.lines.length === 0) {
        // Always keep at least one row visible so the user can
        // re-enter without clicking Add first.
        addRestockLine();
    }
}

async function submitRestockModal(): Promise<void> {
    restockModalBusy.value = true;
    restockModalErrors.value = {};
    restockModalError.value = null;
    try {
        // Strip blank lines (the user can leave an empty row
        // dangling without it counting). The validation rule
        // requires at least 1 — if everything's blank, the
        // server will reject with a clean message.
        const cleanLines: RestockLinePayload[] = restockForm2.lines
            .filter((l) => l.ingredient_uuid && l.quantity)
            .map((l) => ({
                ingredient_uuid: l.ingredient_uuid,
                quantity_requested: l.quantity,
                note: l.note.trim() || null,
                unit: wireUnit(l.unit),
            }));

        if (restockModalMode.value === 'create') {
            if (!restockForm2.branch_uuid) {
                restockModalError.value = t('inventory.restock.create_modal.branch_placeholder');
                return;
            }
            await createRestockRequest(restockForm2.branch_uuid, {
                lines: cleanLines,
                note: restockForm2.note.trim() || null,
            });
        } else if (restockModalTarget.value) {
            await updateRestockRequest(restockModalTarget.value.uuid, {
                lines: cleanLines,
                note: restockForm2.note.trim() || null,
            });
        }
        restockModalOpen.value = false;
        await fetchRestockRequests();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            restockModalErrors.value = err.payload.errors;
            restockModalError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            restockModalError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            restockModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        restockModalBusy.value = false;
    }
}

function openShow(req: RestockRequest): void {
    showTarget.value = req;
    showOpen.value = true;
}

// =================== Phase 6 — Branch transfer flows ============

const transferHasDuplicates = computed<boolean>(() => {
    const seen = new Set<string>();
    for (const line of transferForm.lines) {
        if (!line.ingredient_uuid) continue;
        if (seen.has(line.ingredient_uuid)) return true;
        seen.add(line.ingredient_uuid);
    }
    return false;
});

function openCreateTransfer(): void {
    transferForm.from_branch_uuid = selectedBranchUuid.value ?? (branches.value[0]?.uuid ?? '');
    transferForm.to_branch_uuid = '';
    transferForm.note = '';
    transferForm.lines = [{ ingredient_uuid: '', quantity: '', unit: '' }];
    transferModalErrors.value = {};
    transferModalError.value = null;
    transferModalOpen.value = true;
}

function addTransferLine(): void {
    transferForm.lines.push({ ingredient_uuid: '', quantity: '', unit: '' });
}

/** Resolve an ingredient (for its alt_units) from a line's uuid. */
function ingredientByUuid(uuid: string): Ingredient | null {
    if (!uuid) return null;
    return ingredients.value.find((i) => i.uuid === uuid) ?? null;
}

function removeTransferLine(idx: number): void {
    transferForm.lines.splice(idx, 1);
    if (transferForm.lines.length === 0) {
        addTransferLine();
    }
}

async function submitTransferModal(): Promise<void> {
    transferModalBusy.value = true;
    transferModalErrors.value = {};
    transferModalError.value = null;
    try {
        if (!transferForm.from_branch_uuid) {
            transferModalError.value = t('inventory.transfers.create_modal.from_placeholder');
            return;
        }
        if (!transferForm.to_branch_uuid) {
            transferModalError.value = t('inventory.transfers.create_modal.to_placeholder');
            return;
        }
        if (transferForm.from_branch_uuid === transferForm.to_branch_uuid) {
            transferModalError.value = t('inventory.transfers.create_modal.same_branch');
            return;
        }
        const cleanLines: BranchTransferLinePayload[] = transferForm.lines
            .filter((l) => l.ingredient_uuid && l.quantity)
            .map((l) => ({ ingredient_uuid: l.ingredient_uuid, quantity: l.quantity, unit: wireUnit(l.unit) }));
        if (cleanLines.length === 0) {
            transferModalError.value = t('inventory.transfers.create_modal.no_lines');
            return;
        }

        await createBranchTransfer(transferForm.from_branch_uuid, {
            to_branch_uuid: transferForm.to_branch_uuid,
            note: transferForm.note.trim() || null,
            lines: cleanLines,
        });
        transferModalOpen.value = false;
        // A transfer moves stock at BOTH branches + writes paired
        // transfer_out/transfer_in ledger rows, so refresh the list and
        // (if a branch is being viewed) its stock + movement tabs.
        await fetchBranchTransfers();
        if (selectedBranchUuid.value !== null) {
            await Promise.all([fetchBranchStock(), fetchMovements()]);
        }
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            transferModalErrors.value = err.payload.errors;
            transferModalError.value = t('inventory.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            transferModalError.value = String((err.payload as { message?: unknown }).message ?? 'Failed');
        } else {
            transferModalError.value = err instanceof Error ? err.message : 'Failed';
        }
    } finally {
        transferModalBusy.value = false;
    }
}

async function doSubmitRequest(req: RestockRequest): Promise<void> {
    try {
        await submitRestockRequest(req.uuid);
        await fetchRestockRequests();
    } catch (err) {
        error.value = extractMessage(err, 'Failed to submit request');
    }
}

function openReview(req: RestockRequest, mode: 'approve' | 'reject'): void {
    reviewTarget.value = req;
    reviewMode.value = mode;
    reviewNote.value = '';
    reviewError.value = null;
    reviewOpen.value = true;
}

async function submitReview(): Promise<void> {
    if (!reviewTarget.value) return;
    reviewBusy.value = true;
    reviewError.value = null;
    try {
        if (reviewMode.value === 'approve') {
            await approveRestockRequest(reviewTarget.value.uuid, {
                note: reviewNote.value.trim() || null,
            });
        } else {
            if (reviewNote.value.trim() === '') {
                reviewError.value = t('inventory.restock.review_modal.reject_hint');
                return;
            }
            await rejectRestockRequest(reviewTarget.value.uuid, {
                note: reviewNote.value.trim(),
            });
        }
        reviewOpen.value = false;
        await fetchRestockRequests();
    } catch (err) {
        reviewError.value = extractMessage(err, 'Failed to review request');
    } finally {
        reviewBusy.value = false;
    }
}

function openCancel(req: RestockRequest): void {
    cancelTarget.value = req;
    cancelNote.value = '';
    cancelError.value = null;
    cancelOpen.value = true;
}

async function submitCancel(): Promise<void> {
    if (!cancelTarget.value) return;
    cancelBusy.value = true;
    cancelError.value = null;
    try {
        await cancelRestockRequest(cancelTarget.value.uuid, {
            note: cancelNote.value.trim() || null,
        });
        cancelOpen.value = false;
        await fetchRestockRequests();
    } catch (err) {
        cancelError.value = extractMessage(err, 'Failed to cancel request');
    } finally {
        cancelBusy.value = false;
    }
}

// F5 — the unit each line's allocation is typed in ('' = the stored unit), keyed like allocateOverrides.
const allocateUnits = reactive<Record<string, string>>({});

/**
 * F5 — the ingredient behind a restock line for its unit picker (its pack
 * sizes and container from the list; the line's own unit as a fallback).
 */
function restockLineIngredient(line: { ingredient?: { uuid: string } | null; unit_at_set: IngredientUnit }): Ingredient | { unit: IngredientUnit } {
    return ingredientByUuid(line.ingredient?.uuid ?? '') ?? { unit: line.unit_at_set };
}

/** F5 — the ingredient behind a restock suggestion for its unit picker. */
function suggestionIngredient(s: RestockSuggestion): Ingredient | { unit: IngredientUnit } {
    return ingredientByUuid(s.ingredient_uuid) ?? { unit: s.unit as IngredientUnit };
}

/** F5 — what a stored amount reopens as in an amount + unit input: "36" + "l" for 36000 ml ('' = the stored unit). */
function entryFor(quantity: string | number | null | undefined, storedUnit: string): { amount: string; unit: string } {
    const holds = holdsEntry(quantity, storedUnit);
    if (holds.amount === '') return { amount: trimAmount(parseFloat(String(quantity ?? '0')) || 0), unit: '' };
    return { amount: holds.amount, unit: holds.unit === storedUnit ? '' : holds.unit };
}

/** F5 — a line's typed allocation in the stored unit (to compare with what was requested). */
function allocatedStored(line: RestockRequestLine): number {
    const typed = parseFloat(allocateOverrides[String(line.id)] ?? '0');
    if (!Number.isFinite(typed)) return 0;
    return typed * ingredientUnitFactor(restockLineIngredient(line), allocateUnits[String(line.id)] ?? '');
}

function openAllocate(req: RestockRequest): void {
    allocateTarget.value = req;
    // Default every line's allocate input to the requested
    // quantity. The user can then over-write the ones they're
    // sending less of. F5 — shown in the friendly unit (36 l, not 36000 ml).
    Object.keys(allocateOverrides).forEach((k) => delete allocateOverrides[k]);
    Object.keys(allocateUnits).forEach((k) => delete allocateUnits[k]);
    for (const line of req.lines ?? []) {
        const entry = entryFor(line.quantity_requested, line.unit_at_set);
        allocateOverrides[String(line.id)] = entry.amount;
        allocateUnits[String(line.id)] = entry.unit;
    }
    allocateError.value = null;
    allocateOpen.value = true;
}

const allocateHasOver = computed<boolean>(() => {
    if (!allocateTarget.value) return false;
    for (const line of allocateTarget.value.lines ?? []) {
        const requested = parseFloat(line.quantity_requested);
        if (allocatedStored(line) > requested + 1e-9) return true;
    }
    return false;
});

async function submitAllocate(): Promise<void> {
    if (!allocateTarget.value) return;
    allocateBusy.value = true;
    allocateError.value = null;
    try {
        // Build the allocations map. Convert string -> int key
        // on the way out (the API client takes Record<number,...>).
        // F5 — each with the unit it was typed in; the server converts.
        const allocations: Record<number, string> = {};
        const units: Record<number, string> = {};
        for (const line of allocateTarget.value.lines ?? []) {
            allocations[line.id] = allocateOverrides[String(line.id)] ?? line.quantity_requested;
            const unit = allocateUnits[String(line.id)] ?? '';
            if (unit !== '') units[line.id] = unit;
        }
        await allocateRestockRequest(allocateTarget.value.uuid, Object.keys(units).length > 0 ? { allocations, units } : { allocations });
        allocateOpen.value = false;
        await Promise.all([
            fetchRestockRequests(),
            // Allocations write stock movements at the requesting
            // branch — refresh the stock view if we're looking at
            // that branch right now.
            allocateTarget.value.branch?.uuid === selectedBranchUuid.value
                ? Promise.all([fetchBranchStock(), fetchMovements()])
                : Promise.resolve(),
        ]);
    } catch (err) {
        allocateError.value = extractMessage(err, 'Failed to allocate request');
    } finally {
        allocateBusy.value = false;
    }
}

// Phase A — resolved-by-purchase closure (no stock movement; the
// goods entered via a purchase record).
const purchasedOpen = ref(false);
const purchasedBusy = ref(false);
const purchasedError = ref<string | null>(null);
const purchasedTarget = ref<RestockRequest | null>(null);
const purchasedNote = ref('');

function openPurchased(req: RestockRequest): void {
    purchasedTarget.value = req;
    purchasedNote.value = '';
    purchasedError.value = null;
    purchasedOpen.value = true;
}

async function submitPurchased(): Promise<void> {
    if (!purchasedTarget.value) return;
    purchasedBusy.value = true;
    purchasedError.value = null;
    try {
        await resolvePurchasedRestockRequest(purchasedTarget.value.uuid, {
            note: purchasedNote.value.trim() || null,
        });
        purchasedOpen.value = false;
        // No stock changed — only the request list needs refreshing.
        await fetchRestockRequests();
    } catch (err) {
        purchasedError.value = extractMessage(err, 'Failed to close request');
    } finally {
        purchasedBusy.value = false;
    }
}

// Shared helper — every Phase 5c lifecycle action uses the
// same error-message extraction pattern.
function extractMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        return String((err.payload as { message?: unknown }).message ?? fallback);
    }
    if (err instanceof Error) return err.message;
    return fallback;
}

// =================== Smart restock suggestions flows =============

function reasonBadgeClass(reason: RestockSuggestion['reason']): string {
    switch (reason) {
        case 'below_threshold_and_forecast':
            return 'bg-rose-100 text-rose-700';
        case 'below_threshold':
            return 'bg-amber-100 text-amber-700';
        case 'consumption_forecast':
            return 'bg-indigo-100 text-indigo-700';
    }
}

function reasonLabel(reason: RestockSuggestion['reason']): string {
    return t(`inventory.restock_suggestions.reasons.${reason}`);
}

async function fetchSuggestions(): Promise<void> {
    if (selectedBranchUuid.value === null) {
        suggestRows.value = [];
        suggestLoaded.value = true;
        return;
    }
    suggestLoading.value = true;
    suggestError.value = null;
    try {
        const response = await getRestockSuggestions(selectedBranchUuid.value, {
            windowDays: suggestWindowDays.value,
            coverDays: suggestCoverDays.value,
        });
        suggestRows.value = response.data.map((s) => {
            // F5 — offered in the friendly unit (36 l, not 36000 ml) when it
            // converts back exactly; else the server's string verbatim.
            const entry = entryFor(s.suggested_quantity, s.unit);
            return { suggestion: s, include: true, qty: entry.amount, unit: entry.unit };
        });
        suggestLoaded.value = true;
    } catch (err) {
        suggestError.value = extractMessage(err, t('inventory.restock_suggestions.load_failed'));
    } finally {
        suggestLoading.value = false;
    }
}

function openSuggestions(): void {
    if (selectedBranchUuid.value === null) return;
    suggestOpen.value = true;
    suggestError.value = null;
    suggestLoaded.value = false;
    suggestRows.value = [];
    suggestNote.value = '';
    void fetchSuggestions();
}

// Re-fetch when the window / cover knobs change — but only while
// the panel is open (avoids a fetch on initial ref creation).
watch([suggestWindowDays, suggestCoverDays], () => {
    if (suggestOpen.value) void fetchSuggestions();
});

async function submitSuggestions(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    const lines: RestockLinePayload[] = suggestRows.value
        .filter((r) => r.include && String(r.qty).trim() !== '')
        .map((r) => ({
            ingredient_uuid: r.suggestion.ingredient_uuid,
            // Send the (possibly edited) quantity through as a string,
            // F5 — with the unit it is typed in (the server converts).
            quantity_requested: r.qty,
            unit: wireUnit(r.unit),
        }));
    if (lines.length === 0) {
        suggestError.value = t('inventory.restock_suggestions.no_selection');
        return;
    }
    suggestCreating.value = true;
    suggestError.value = null;
    try {
        await createRestockRequest(selectedBranchUuid.value, {
            lines,
            note: suggestNote.value.trim() || null,
        });
        suggestOpen.value = false;
        success.value = t('inventory.restock_suggestions.created', { count: lines.length });
        // The page lists restock requests — refresh so the new one
        // (and the tab count badge) reflect immediately.
        await fetchRestockRequests();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            suggestError.value = t('inventory.validation_summary');
        } else {
            suggestError.value = extractMessage(err, t('inventory.restock_suggestions.create_failed'));
        }
    } finally {
        suggestCreating.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('inventory.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('inventory.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('inventory.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex flex-wrap gap-1 rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'ingredients' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'ingredients'"
                >
                    <Boxes class="size-4" />
                    {{ t('inventory.tabs.ingredients') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ ingredients.length }}</span>
                </button>
                <!-- PD3a — physical items: things that cannot be eaten. -->
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'physical_items' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'physical_items'"
                >
                    <Lightbulb class="size-4" />
                    {{ t('inventory.tabs.physical_items') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ physicalItems.length }}</span>
                </button>
                <!-- LAUNCH-P3 P3-4 — prep items: a recipe and a yield, no stock. -->
                <button
                    type="button"
                    data-test="prep-items-tab-button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'prep_items' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'prep_items'"
                >
                    <ChefHat class="size-4" />
                    {{ t('inventory.tabs.prep_items') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ prepIngredients.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'suppliers' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'suppliers'"
                >
                    <Users class="size-4" />
                    {{ t('inventory.tabs.suppliers') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ suppliers.length }}</span>
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'stock' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'stock'"
                >
                    <Package class="size-4" />
                    {{ t('inventory.tabs.stock') }}
                </button>
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'movements' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'movements'"
                >
                    <History class="size-4" />
                    {{ t('inventory.tabs.movements') }}
                </button>
                <!-- Phase 5c — waste tab. Branch-scoped same as
                     Stock + Movements; uses the same branch picker. -->
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'waste' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'waste'"
                >
                    <Trash class="size-4" />
                    {{ t('inventory.tabs.waste') }}
                </button>
                <!-- Phase A — day-end stock counts tab. Branch-scoped,
                     same picker as Stock / Movements / Waste. -->
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'stock_counts' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'stock_counts'"
                >
                    <ClipboardCheck class="size-4" />
                    {{ t('inventory.tabs.stock_counts') }}
                </button>
                <!-- Phase 5c — restock requests tab. NOT branch-
                     scoped: HQ reviewers see requests from every
                     branch in one inbox. -->
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'restock_requests' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'restock_requests'"
                >
                    <ClipboardList class="size-4" />
                    {{ t('inventory.tabs.restock_requests') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ restockRequests.length }}</span>
                </button>
                <!-- Phase 6 — branch→branch transfers tab. NOT branch-
                     scoped: an immediate atomic move, no approval flow. -->
                <button
                    type="button"
                    class="flex-1 min-w-max inline-flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-semibold transition"
                    :class="activeTab === 'transfers' ? 'bg-slate-950 text-white shadow' : 'text-slate-700 hover:bg-slate-50'"
                    @click="activeTab = 'transfers'"
                >
                    <ArrowLeftRight class="size-4" />
                    {{ t('inventory.tabs.transfers') }}
                    <span class="rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-bold">{{ branchTransfers.length }}</span>
                </button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>
            <div v-if="success" class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                <span>{{ success }}</span>
                <button type="button" class="rounded p-0.5 text-emerald-600 transition hover:bg-emerald-100" @click="success = null">
                    <X class="size-4" />
                </button>
            </div>

            <!-- ================== INGREDIENTS TAB ================== -->
            <section v-if="activeTab === 'ingredients'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateIngredient"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.add_ingredient') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="ingredients.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Boxes class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_ingredients') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.column') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.default_cost') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.min_threshold') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.supplier') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="ing in ingredients" :key="ing.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ ing.name }}</span>
                                    <span v-if="ing.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ ing.name_ar }}</span>
                                </td>
                                <!-- LAUNCH item kind — the kind, not a unit; an older kg / l / pack / box one notes its stored unit. -->
                                <td class="px-5 py-4 text-sm text-slate-700" data-test="ingredient-kind">
                                    {{ t(`item_kind.kinds.${kindOfUnit(ing.unit)}`) }}
                                    <span v-if="isLegacyStoredUnit(ing.unit)" class="block text-[10px] text-slate-400">{{ t('item_kind.stored_in', { unit: ing.unit }) }}</span>
                                </td>
                                <!-- F1 — "0.150 OMR / l", not "0.00015 OMR / ml". -->
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-950" data-test="ingredient-cost">{{ friendlyCost(ing.default_unit_cost, ing.unit).amount }} <span class="text-[10px] text-slate-400">OMR / {{ friendlyCost(ing.default_unit_cost, ing.unit).unit }}</span></td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-500">{{ ing.min_stock_threshold !== null ? qty(ing.min_stock_threshold, ing.unit) : '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ ing.primary_supplier?.name ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(ing.status)">
                                        {{ statusLabel(ing.status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex gap-2">
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditIngredient(ing)">
                                            <Pencil class="size-3" /> {{ t('inventory.actions.edit') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-50" @click="openWarehouseDialog(ing)">
                                            <Boxes class="size-3" /> {{ t('inventory.actions.warehouse') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="ingDeleteTarget = ing">
                                            <Trash2 class="size-3" /> {{ t('inventory.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============ LAUNCH-P3 P3-4 — PREP ITEMS TAB ============ -->
            <PrepItemsTab v-if="activeTab === 'prep_items'" />

            <!-- ============ PD3a — PHYSICAL ITEMS TAB ============ -->
            <section v-if="activeTab === 'physical_items'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-2xl text-xs text-slate-500">{{ t('inventory.physical_items.subtitle') }}</p>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreatePhysicalItem"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.physical_items.add') }}
                    </button>
                </div>

                <div v-if="physicalItems.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Lightbulb class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.physical_items.empty') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.physical_items.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.physical_items.table.kind') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.physical_items.table.cost') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.physical_items.table.low_stock') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.physical_items.table.central') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="item in physicalItems" :key="item.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <span class="block text-sm font-semibold text-slate-950">{{ item.name }}</span>
                                    <span v-if="item.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ item.name_ar }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="item.purpose === 'packaging' ? 'bg-sky-100 text-sky-700' : 'bg-slate-200 text-slate-700'">
                                        {{ t(`inventory.physical_items.purposes.${item.purpose}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end text-sm tabular-nums text-slate-700">{{ item.cost_price ?? '—' }}</td>
                                <td class="px-5 py-3 text-end text-sm tabular-nums text-slate-700">{{ item.low_stock_threshold ?? '—' }}</td>
                                <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums text-slate-950">{{ item.central_quantity }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="item.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'">
                                        {{ t(`catalogue.statuses.${item.status ?? 'active'}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <div class="inline-flex gap-2">
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditPhysicalItem(item)">
                                            <Pencil class="size-3" /> {{ t('catalogue.actions.edit') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-50" @click="physicalItemStockTarget = item">
                                            <Package class="size-3" /> {{ t('inventory.physical_items.stock') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50" @click="physicalItemDeleteTarget = item">
                                            <Trash2 class="size-3" /> {{ t('inventory.physical_items.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================== SUPPLIERS TAB ================== -->
            <section v-if="activeTab === 'suppliers'" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateSupplier"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.add_supplier') }}
                    </button>
                </div>

                <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="suppliers.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Users class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_suppliers') }}</p>
                </div>
                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="sup in suppliers" :key="sup.id" class="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-200">
                        <header class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ sup.name }}</h2>
                                <p v-if="sup.contact" class="text-xs text-slate-500">{{ sup.contact }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="statusBadgeClass(sup.status)">
                                {{ statusLabel(sup.status) }}
                            </span>
                        </header>
                        <p v-if="sup.notes" class="text-xs text-slate-600">{{ sup.notes }}</p>
                        <p class="text-xs text-slate-500">{{ t('inventory.table.ingredients') }}: {{ sup.ingredients_count ?? 0 }}</p>
                        <div v-if="canManage" class="mt-auto flex gap-1.5 pt-2">
                            <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditSupplier(sup)">
                                <Pencil class="size-3" /> {{ t('inventory.actions.edit') }}
                            </button>
                            <button
                                type="button"
                                :disabled="(sup.ingredients_count ?? 0) > 0"
                                :title="(sup.ingredients_count ?? 0) > 0 ? t('inventory.delete_supplier_blocked') : ''"
                                class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="supDeleteTarget = sup"
                            >
                                <Trash2 class="size-3" /> {{ t('inventory.actions.delete') }}
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ================== STOCK TAB ================== -->
            <section v-if="activeTab === 'stock' || activeTab === 'movements' || activeTab === 'stock_counts'" class="space-y-4">
                <!-- Branch picker — shared by stock + movements -->
                <label class="block max-w-md">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <Building2 class="me-1 inline size-3" />
                        {{ t('inventory.branch') }}
                    </span>
                    <select v-model="selectedBranchUuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="branch in branches" :key="branch.uuid" :value="branch.uuid">
                            {{ branch.name }}
                        </option>
                    </select>
                </label>

                <div v-if="branches.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Building2 class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-medium text-slate-600">{{ t('inventory.no_branches') }}</p>
                </div>
            </section>

            <section v-if="activeTab === 'stock' && branches.length > 0" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <!-- LAUNCH-P2 P2-7 — every ingredient, or just the low ones. -->
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-sm" role="group" data-test="stock-filter">
                        <button
                            type="button"
                            class="rounded-md px-3 py-1.5 text-sm font-semibold transition"
                            :class="stockFilter === 'all' ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            @click="stockFilter = 'all'"
                        >
                            {{ t('inventory.stock.all') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold transition"
                            :class="stockFilter === 'low' ? 'bg-amber-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            @click="stockFilter = 'low'"
                        >
                            {{ t('inventory.stock.low_filter') }}
                            <span v-if="branchStockMeta && (branchStockMeta.negative_count + branchStockMeta.below_minimum_count) > 0" class="rounded-full bg-white/80 px-1.5 text-[10px] font-bold text-amber-700">
                                {{ branchStockMeta.negative_count + branchStockMeta.below_minimum_count }}
                            </span>
                        </button>
                    </div>

                    <div v-if="(canViewInventory || canManage) && ingredients.length > 0" class="flex flex-wrap justify-end gap-2">
                        <button
                            v-if="canViewInventory && selectedBranchUuid"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100"
                            @click="openSuggestions"
                        >
                            <Lightbulb class="size-4" />
                            {{ t('inventory.restock_suggestions.action') }}
                        </button>
                        <!-- LAUNCH-P2 P2-4 — one way in for the pilot: goods received. -->
                        <RouterLink
                            v-if="canManage && singleStockIn"
                            :to="{ name: 'merchant.purchase-receipts.create' }"
                            class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                            data-test="goods-received-link"
                        >
                            <Plus class="size-4" />
                            {{ t('inventory.stock.goods_received') }}
                        </RouterLink>
                        <button
                            v-if="canManage && !singleStockIn"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                            data-test="restock-button"
                            @click="openRestock(null, ingredients[0])"
                        >
                            <Plus class="size-4" />
                            {{ t('inventory.actions.restock') }}
                        </button>
                        <!-- Phase A — piece-aware purchase batch. -->
                        <button
                            v-if="canManage && !singleStockIn"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100"
                            data-test="purchase-button"
                            @click="openPurchase(null, ingredients[0])"
                        >
                            <ShoppingCart class="size-4" />
                            {{ t('inventory.actions.purchase') }}
                        </button>
                    </div>
                </div>

                <!-- LAUNCH-P2 P2-7 — the branch's stock value and its warnings. -->
                <div v-if="branchStockMeta" class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm shadow-sm" data-test="stock-summary">
                    <span class="text-slate-500">{{ t('inventory.stock.total_value') }}: <span class="font-semibold tabular-nums text-slate-900">{{ branchStockMeta.total_value }}</span></span>
                    <span :class="branchStockMeta.negative_count > 0 ? 'font-semibold text-rose-600' : 'text-slate-400'">{{ t('inventory.stock.negative_count', { count: branchStockMeta.negative_count }) }}</span>
                    <span :class="branchStockMeta.below_minimum_count > 0 ? 'font-semibold text-amber-600' : 'text-slate-400'">{{ t('inventory.stock.below_minimum_count', { count: branchStockMeta.below_minimum_count }) }}</span>
                </div>

                <div v-if="branchStock.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Package class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ stockFilter === 'low' ? t('inventory.stock.no_low') : t('inventory.empty_stock') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.quantity') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.stock.value') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.health') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.last_movement') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in branchStock" :key="row.ingredient_id" class="transition hover:bg-slate-50" :class="stockRowClass(row.stock_status)" :data-stock-status="row.stock_status">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.ingredient?.name ?? '—' }}</span>
                                    <span v-if="row.ingredient?.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ row.ingredient.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums" :class="stockQuantityClass(row.stock_status)">
                                    <!-- A8 — "24 l", not "24000.000 ml". -->
                                    {{ friendlyAmount(row.quantity, row.ingredient?.unit).amount }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ friendlyAmount(row.quantity, row.ingredient?.unit).unit }}</span>
                                    <span v-if="row.ingredient?.min_stock_threshold" class="block text-[10px] font-normal text-slate-400">{{ t('inventory.stock.minimum', { quantity: qty(row.ingredient.min_stock_threshold, row.ingredient.unit) }) }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums" :class="Number(row.stock_value) < 0 ? 'text-rose-600' : 'text-slate-700'">{{ row.stock_value }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="stockStatusBadgeClass(row.stock_status)">
                                        {{ t(`inventory.stock.status.${row.stock_status}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDate(row.last_movement_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div v-if="canManage" class="inline-flex gap-2">
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openAdjust(row)">
                                            <Minus class="size-3" /> {{ t('inventory.actions.adjust') }}
                                        </button>
                                        <button v-if="!singleStockIn" type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100" @click="openRestock(row)">
                                            <Plus class="size-3" /> {{ t('inventory.actions.restock') }}
                                        </button>
                                        <button v-if="!singleStockIn" type="button" class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100" @click="openPurchase(row)">
                                            <ShoppingCart class="size-3" /> {{ t('inventory.actions.purchase') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================== MOVEMENTS TAB ================== -->
            <section v-if="activeTab === 'movements' && branches.length > 0" class="space-y-4">
                <div class="flex flex-wrap gap-3">
                    <label class="block min-w-xs flex-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.movements_filter.ingredient') }}</span>
                        <select v-model="movementFilters.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.movements_filter.all_ingredients') }}</option>
                            <option v-for="ing in ingredients" :key="ing.uuid" :value="ing.uuid">{{ ing.name }}</option>
                        </select>
                    </label>
                    <label class="block min-w-xs flex-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.movements_filter.type') }}</span>
                        <select v-model="movementFilters.type" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.movements_filter.all_types') }}</option>
                            <option value="initial">{{ t('inventory.movement_types.initial') }}</option>
                            <option value="restock">{{ t('inventory.movement_types.restock') }}</option>
                            <option value="adjustment">{{ t('inventory.movement_types.adjustment') }}</option>
                            <option value="waste">{{ t('inventory.movement_types.waste') }}</option>
                            <option value="loss">{{ t('inventory.movement_types.loss') }}</option>
                            <option value="sale_consumption">{{ t('inventory.movement_types.sale_consumption') }}</option>
                            <option value="refund_return">{{ t('inventory.movement_types.refund_return') }}</option>
                            <option value="addon_consumption">{{ t('inventory.movement_types.addon_consumption') }}</option>
                            <option value="transfer_in">{{ t('inventory.movement_types.transfer_in') }}</option>
                            <option value="transfer_out">{{ t('inventory.movement_types.transfer_out') }}</option>
                            <option value="allocation_in">{{ t('inventory.movement_types.allocation_in') }}</option>
                            <option value="production_consumption">{{ t('inventory.movement_types.production_consumption') }}</option>
                            <option value="production_return">{{ t('inventory.movement_types.production_return') }}</option>
                            <!-- LAUNCH-P2 P2-6 — late pre-count movements folded into a count. -->
                            <option value="count_correction">{{ t('inventory.movement_types.count_correction') }}</option>
                        </select>
                    </label>
                </div>

                <div v-if="movements && movements.data.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <History class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_movements') }}</p>
                </div>
                <div v-else-if="movements" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.when') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.type') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.fields.ingredient') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.qty') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.unit_cost') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.note') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.by') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="m in movements.data" :key="m.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-xs text-slate-500 whitespace-nowrap">{{ formatDate(m.occurred_at) }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-700">
                                        {{ movementTypeLabel(m.movement_type) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-900">{{ m.ingredient?.name ?? '—' }}</td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums" :class="isOutflow(m.quantity) ? 'text-rose-700' : 'text-emerald-700'">
                                    {{ friendlyAmount(m.quantity, m.ingredient?.unit).amount }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ friendlyAmount(m.quantity, m.ingredient?.unit).unit }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-xs tabular-nums text-slate-500">{{ friendlyCost(m.unit_cost_at_time, m.ingredient?.unit).amount }} <span class="text-[10px] text-slate-400">OMR / {{ friendlyCost(m.unit_cost_at_time, m.ingredient?.unit).unit }}</span></td>
                                <td class="px-5 py-4 text-xs text-slate-600 max-w-xs truncate" :title="m.note ?? ''">{{ m.note ?? '—' }}</td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ m.recorded_by?.name ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Pagination -->
                    <div v-if="movements.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-600">
                        <span>{{ movements.meta.current_page }} / {{ movements.meta.last_page }} ({{ movements.meta.total }})</span>
                        <div class="flex gap-1">
                            <button type="button" :disabled="movements.meta.current_page <= 1" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="movementsPage = Math.max(1, movements.meta.current_page - 1)">‹</button>
                            <button type="button" :disabled="movements.meta.current_page >= movements.meta.last_page" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="movementsPage = Math.min(movements.meta.last_page, movements.meta.current_page + 1)">›</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================== WASTE TAB ================== -->
            <!-- Phase 5c. Branch-scoped (uses the shared branch
                 picker). Empty state when no branch picked. -->
            <section v-if="activeTab === 'waste'" class="space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="grid gap-3 sm:grid-cols-3 sm:flex-1">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.branch') }}</span>
                            <select v-model="selectedBranchUuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="null">{{ t('inventory.no_branches') }}</option>
                                <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.filter_ingredient') }}</span>
                            <select v-model="wasteFilters.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="">{{ t('inventory.waste.filter_ingredient_all') }}</option>
                                <option v-for="i in ingredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.filter_reason') }}</span>
                            <select v-model="wasteFilters.reason" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="">{{ t('inventory.waste.filter_reason_all') }}</option>
                                <option v-for="r in wasteReasons" :key="r" :value="r">{{ t(`inventory.waste.reasons.${r}`) }}</option>
                            </select>
                        </label>
                    </div>
                    <button
                        v-if="canManage && selectedBranchUuid"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-rose-600 to-orange-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-rose-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openRecordWaste"
                    >
                        <Trash class="size-4" />
                        {{ t('inventory.actions.record_waste') }}
                    </button>
                </div>

                <!-- LAUNCH-P3 fix order 1, K3 — sell-but-warn: the waste was
                     recorded; the server says what it took below zero. -->
                <p v-if="wasteWarning" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800" data-test="waste-warning">{{ wasteWarning }}</p>

                <div v-if="!selectedBranchUuid" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Trash class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.waste.no_branch') }}</p>
                </div>
                <div v-else-if="!waste || waste.data.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Trash class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.waste.empty') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.occurred_at') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.qty') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.reason') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.cost') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.recorded_by') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="w in waste.data" :key="w.id" class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-sm text-slate-600">{{ formatDate(w.occurred_at) }}</td>
                                <td class="px-5 py-3 text-sm font-medium text-slate-900">
                                    {{ w.ingredient ? (isArabic && w.ingredient.name_ar ? w.ingredient.name_ar : w.ingredient.name) : '—' }}
                                    <!-- LAUNCH-P3 K4 — one record of a prep item's waste. -->
                                    <span v-if="w.prep_item" class="ms-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700" data-test="waste-prep-item">{{ t('inventory.waste.from_prep', { name: isArabic && w.prep_item.name_ar ? w.prep_item.name_ar : w.prep_item.name }) }}</span>
                                </td>
                                <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums text-rose-700">
                                    -{{ friendlyAmount(w.quantity, w.unit_at_set).amount }} <span class="text-[10px] text-slate-500">{{ friendlyAmount(w.quantity, w.unit_at_set).unit }}</span>
                                </td>
                                <td class="px-5 py-3 text-sm">
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                        {{ t(`inventory.waste.reasons.${w.reason}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums text-slate-900">
                                    {{ w.total_cost }} <span class="text-[10px] text-slate-500">OMR</span>
                                </td>
                                <td class="px-5 py-3 text-sm text-slate-600">{{ w.recorded_by?.name ?? '—' }}</td>
                                <td class="px-5 py-3 text-sm text-slate-600 max-w-xs truncate" :title="w.notes ?? ''">{{ w.notes || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="waste.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-600">
                        <span>{{ waste.meta.current_page }} / {{ waste.meta.last_page }} ({{ waste.meta.total }})</span>
                        <div class="flex gap-1">
                            <button type="button" :disabled="waste.meta.current_page <= 1" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="wastePage = Math.max(1, waste.meta.current_page - 1)">‹</button>
                            <button type="button" :disabled="waste.meta.current_page >= waste.meta.last_page" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="wastePage = Math.min(waste.meta.last_page, waste.meta.current_page + 1)">›</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================== PHASE A — DAY-END STOCK COUNTS TAB ================== -->
            <!-- Branch-scoped (shared picker above). Each count is a
                 reconciled snapshot: counted vs expected per ingredient,
                 with the variance movements already written. -->
            <section v-if="activeTab === 'stock_counts' && branches.length > 0" class="space-y-4">
                <div class="flex justify-end">
                    <button
                        v-if="canManage && selectedBranchUuid"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-teal-600 to-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCount"
                    >
                        <ClipboardCheck class="size-4" />
                        {{ t('inventory.counts.new_count') }}
                    </button>
                </div>

                <div v-if="!stockCounts || stockCounts.data.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <ClipboardCheck class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.counts.empty') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ t('inventory.counts.empty_hint') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.counted_at') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.recorded_by') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.lines') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.shortfall_value') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.waste.notes') }}</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="count in stockCounts.data" :key="count.uuid">
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-5 py-3 text-sm font-medium text-slate-700">{{ formatDate(count.counted_at) }}</td>
                                    <td class="px-5 py-3 text-sm text-slate-600">{{ count.recorded_by ?? '—' }}</td>
                                    <td class="px-5 py-3 text-end text-sm tabular-nums text-slate-700">{{ count.lines.length }}</td>
                                    <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums" :class="countShortfallValue(count) < 0 ? 'text-rose-600' : 'text-emerald-600'">
                                        {{ countShortfallValue(count) < 0 ? countShortfallValue(count).toFixed(3) : '0.000' }}
                                    </td>
                                    <td class="px-5 py-3 text-xs text-slate-500">{{ count.note ?? '—' }}</td>
                                    <td class="px-5 py-3 text-end">
                                        <button type="button" class="rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="expandedCountUuid = expandedCountUuid === count.uuid ? null : count.uuid">
                                            {{ expandedCountUuid === count.uuid ? t('inventory.counts.hide_lines') : t('inventory.counts.show_lines') }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedCountUuid === count.uuid">
                                    <td colspan="6" class="bg-slate-50/70 px-5 py-3">
                                        <table class="min-w-full text-xs">
                                            <thead>
                                                <tr class="text-start text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                                                    <th class="px-2 py-1 text-start">{{ t('inventory.table.name') }}</th>
                                                    <th class="px-2 py-1 text-end">{{ t('inventory.counts.counted') }}</th>
                                                    <th class="px-2 py-1 text-end">{{ t('inventory.counts.expected') }}</th>
                                                    <th class="px-2 py-1 text-end">{{ t('inventory.counts.variance') }}</th>
                                                    <th class="px-2 py-1 text-end">{{ t('inventory.counts.variance_value') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                <tr v-for="line in count.lines" :key="line.ingredient_id">
                                                    <td class="px-2 py-1.5 font-medium text-slate-700">
                                                        {{ line.ingredient ? (isArabic && line.ingredient.name_ar ? line.ingredient.name_ar : line.ingredient.name) : '—' }}
                                                    </td>
                                                    <td class="px-2 py-1.5 text-end tabular-nums text-slate-700">
                                                        <template v-if="line.counted_pieces !== null">
                                                            {{ trimAmount(parseFloat(line.counted_pieces)) }} {{ line.ingredient?.piece_unit_label ?? t('inventory.units.piece') }}
                                                            <span class="text-slate-400">(= {{ qty(line.counted_units, line.ingredient?.unit) }})</span>
                                                        </template>
                                                        <template v-else>{{ qty(line.counted_units, line.ingredient?.unit) }}</template>
                                                    </td>
                                                    <td class="px-2 py-1.5 text-end tabular-nums text-slate-600">{{ line.expected_units !== undefined ? qty(line.expected_units, line.ingredient?.unit) : '' }}</td>
                                                    <td class="px-2 py-1.5 text-end font-semibold tabular-nums" :class="Number(line.variance_units) < 0 ? 'text-rose-600' : Number(line.variance_units) > 0 ? 'text-amber-600' : 'text-emerald-600'">
                                                        {{ line.variance_units !== undefined ? qty(line.variance_units, line.ingredient?.unit) : '' }}
                                                    </td>
                                                    <td class="px-2 py-1.5 text-end tabular-nums" :class="Number(line.variance_value) < 0 ? 'text-rose-600' : 'text-slate-600'">{{ line.variance_value }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <!-- Pagination -->
                    <div v-if="stockCounts.meta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-600">
                        <span>{{ stockCounts.meta.current_page }} / {{ stockCounts.meta.last_page }} ({{ stockCounts.meta.total }})</span>
                        <div class="flex gap-1">
                            <button type="button" :disabled="stockCounts.meta.current_page <= 1" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="stockCountsPage = Math.max(1, stockCounts.meta.current_page - 1)">‹</button>
                            <button type="button" :disabled="stockCounts.meta.current_page >= stockCounts.meta.last_page" class="rounded border border-slate-200 bg-white px-2 py-1 font-semibold disabled:opacity-50" @click="stockCountsPage = Math.min(stockCounts.meta.last_page, stockCounts.meta.current_page + 1)">›</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ================== RESTOCK REQUESTS TAB ================== -->
            <!-- Phase 5c. NOT branch-scoped: HQ reviewers see
                 requests from every branch in one inbox.
                 Per-row action buttons adapt to status +
                 permissions: Submit / Approve / Reject /
                 Allocate / Cancel are shown only when both the
                 status and the user's role allow it. -->
            <section v-if="activeTab === 'restock_requests'" class="space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="grid gap-3 sm:grid-cols-2 sm:flex-1">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.filter_status') }}</span>
                            <select v-model="restockFilters.status" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="">{{ t('inventory.restock.filter_status_all') }}</option>
                                <option v-for="s in restockStatuses" :key="s" :value="s">{{ t(`inventory.restock.statuses.${s}`) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.filter_branch') }}</span>
                            <select v-model="restockFilters.branch_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="">{{ t('inventory.restock.filter_branch_all') }}</option>
                                <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                            </select>
                        </label>
                    </div>
                    <button
                        v-if="canCreateRestock"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateRestock"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.new_request') }}
                    </button>
                </div>

                <div v-if="restockRequests.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <ClipboardList class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.restock.empty') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.created_at') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.branch') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.lines') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.cost') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.requested_by') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in restockRequests" :key="r.uuid" class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-sm text-slate-600">{{ formatDate(r.created_at) }}</td>
                                <td class="px-5 py-3 text-sm font-medium text-slate-900">{{ r.branch?.name ?? '—' }}</td>
                                <td class="px-5 py-3 text-sm">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="restockStatusBadgeClass(r.status)">
                                        {{ t(`inventory.restock.statuses.${r.status}`) }}
                                    </span>
                                    <span v-if="r.status === 'fulfilled' && r.resolution" class="ms-1 text-[10px] font-medium text-slate-500" :title="r.resolution_note ?? undefined">
                                        {{ t(`inventory.restock.resolution.${r.resolution}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end text-sm tabular-nums text-slate-700">{{ r.totals?.line_count ?? 0 }}</td>
                                <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums text-slate-900">
                                    <span v-if="r.totals && r.totals.line_count > 0">{{ r.totals.allocated_cost }} <span class="text-[10px] text-slate-500">OMR</span></span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-3 text-sm text-slate-600">{{ r.requested_by?.name ?? '—' }}</td>
                                <td class="px-5 py-3 text-end text-sm">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-1">
                                        <button type="button" :title="t('inventory.actions.view')" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="openShow(r)">
                                            {{ t('inventory.restock.row.open') }}
                                        </button>
                                        <button v-if="canCreateRestock && r.status === 'draft'" type="button" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditRestock(r)">
                                            <Pencil class="size-3.5" />
                                        </button>
                                        <button v-if="canCreateRestock && r.status === 'draft'" type="button" class="rounded-lg bg-indigo-600 px-2 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700" @click="doSubmitRequest(r)">
                                            <Send class="me-1 inline size-3.5" />
                                            {{ t('inventory.actions.submit') }}
                                        </button>
                                        <button v-if="canReviewRestock && r.status === 'submitted'" type="button" class="rounded-lg bg-emerald-600 px-2 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700" @click="openReview(r, 'approve')">
                                            <CheckCircle2 class="me-1 inline size-3.5" />
                                            {{ t('inventory.actions.approve') }}
                                        </button>
                                        <button v-if="canReviewRestock && r.status === 'submitted'" type="button" class="rounded-lg bg-rose-600 px-2 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-700" @click="openReview(r, 'reject')">
                                            <XCircle class="me-1 inline size-3.5" />
                                            {{ t('inventory.actions.reject') }}
                                        </button>
                                        <button v-if="canReviewRestock && r.status === 'approved'" type="button" class="rounded-lg bg-amber-600 px-2 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700" @click="openAllocate(r)">
                                            <Package class="me-1 inline size-3.5" />
                                            {{ t('inventory.actions.allocate') }}
                                        </button>
                                        <button v-if="canReviewRestock && r.status === 'approved'" type="button" :title="t('inventory.restock.purchased_modal.hint')" class="rounded-lg border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-100" @click="openPurchased(r)">
                                            {{ t('inventory.actions.resolve_purchased') }}
                                        </button>
                                        <button v-if="canCreateRestock && (r.status === 'draft' || r.status === 'submitted')" type="button" :title="t('inventory.actions.cancel_request')" class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100" @click="openCancel(r)">
                                            <X class="size-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================== PHASE 6 — BRANCH TRANSFERS ================== -->
            <section v-if="activeTab === 'transfers'" class="space-y-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <label class="block sm:flex-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.filter_branch') }}</span>
                        <select v-model="transferFilters.branch_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm sm:max-w-xs">
                            <option value="">{{ t('inventory.transfers.filter_branch_all') }}</option>
                            <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                        </select>
                    </label>
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 transition hover:-translate-y-0.5 hover:shadow-xl"
                        @click="openCreateTransfer"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.new_transfer') }}
                    </button>
                </div>

                <div v-if="branchTransfers.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <ArrowLeftRight class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.transfers.empty') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.transferred_at') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.route') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.lines') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.items') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.transfers.note') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="tr in branchTransfers" :key="tr.uuid" class="align-top hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-sm text-slate-600">{{ formatDate(tr.transferred_at ?? tr.created_at) }}</td>
                                <td class="px-5 py-3 text-sm font-medium text-slate-900">
                                    <span class="inline-flex items-center gap-1.5">
                                        {{ tr.from_branch_name ?? '—' }}
                                        <ArrowLeftRight class="size-3.5 text-slate-400" />
                                        {{ tr.to_branch_name ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end text-sm tabular-nums text-slate-700">{{ tr.lines.length }}</td>
                                <td class="px-5 py-3 text-sm text-slate-600">
                                    <span v-for="(l, i) in tr.lines" :key="l.ingredient_id">{{ l.ingredient_name ?? ('#' + l.ingredient_id) }} ({{ qty(l.quantity, l.unit) }}){{ i < tr.lines.length - 1 ? ', ' : '' }}</span>
                                </td>
                                <td class="px-5 py-3 text-sm text-slate-500">{{ tr.note || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ================== INGREDIENT MODAL ================== -->
        <BaseModal
            v-if="ingModalOpen"
            :title="ingModalMode === 'create' ? t('inventory.ing_modal.create_title') : t('inventory.ing_modal.edit_title')"
            size="xl"
            :loading="ingModalBusy"
            @close="ingModalOpen = false"
        >
                <form id="ing-modal-form" class="space-y-4" @submit.prevent="submitIngredient">
                    <div v-if="ingModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ ingModalError }}
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name') }} *</span>
                            <input v-model="ingForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="ingModalErrors.name" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.name[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name_ar') }}</span>
                            <input v-model="ingForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </div>
                    <!-- LAUNCH item kind — the form asks what KIND of item it is
                         (a new one is stored in g, ml or piece), never a base
                         unit. A2: on edit the kind shows, and is locked once the
                         ingredient is used (today's unit-change rule). -->
                    <fieldset data-test="item-kind" :disabled="kindLocked">
                        <legend class="text-sm font-medium text-slate-700">{{ t('item_kind.question') }} *</legend>
                        <div class="mt-1 grid gap-2 sm:grid-cols-3">
                            <label
                                v-for="k in ITEM_KINDS"
                                :key="k"
                                class="flex flex-col rounded-lg border px-3 py-2.5 transition"
                                :class="[
                                    ingKind === k ? 'border-teal-500 bg-teal-50 ring-2 ring-teal-100' : 'border-slate-200',
                                    kindLocked ? (ingKind === k ? 'cursor-not-allowed' : 'cursor-not-allowed opacity-50') : 'cursor-pointer hover:bg-slate-50',
                                ]"
                                :data-test="`item-kind-${k}`"
                            >
                                <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                    <input type="radio" name="ing-kind" :value="k" :checked="ingKind === k" :disabled="kindLocked" class="size-4 border-slate-300 text-teal-600 focus:ring-teal-500" @change="chooseKind(k)">
                                    {{ t(`item_kind.kinds.${k}`) }}
                                </span>
                                <span class="mt-0.5 ps-6 text-xs text-slate-500">{{ t(`item_kind.examples.${k}`) }}</span>
                            </label>
                        </div>
                        <p v-if="ingKind" class="mt-1 text-xs text-slate-500" data-test="item-kind-units">
                            {{ t(`item_kind.entered_in.${ingKind}`) }}
                            <!-- A2 — an older kg / l / pack / box ingredient keeps its stored unit. -->
                            <span v-if="legacyStoredUnit" class="ms-1 rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-600" data-test="item-kind-stored-in">{{ t('item_kind.stored_in', { unit: legacyStoredUnit }) }}</span>
                        </p>
                        <p v-if="kindLocked" class="mt-1 text-xs font-semibold text-amber-700" data-test="item-kind-locked">{{ t('item_kind.locked') }}</p>
                        <!-- F6 — pack sizes / a count container hold amounts of the stored unit. -->
                        <p v-else-if="kindChangeBlocked" class="mt-1 text-xs font-semibold text-amber-700" data-test="item-kind-change-blocked">{{ t('item_kind.kind_change_blocked') }}</p>
                        <p v-if="ingModalErrors.unit" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.unit[0] }}</p>
                    </fieldset>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <!-- F1 — the cost is typed per kg / l (or per g / ml, or per piece)
                                 and sent per stored unit. -->
                            <span class="text-sm font-medium text-slate-700">{{ ingForm.unit !== '' ? t('item_kind.cost_per', { unit: ingForm.cost_unit }) : t('inventory.fields.default_unit_cost') }} (OMR)</span>
                            <div class="mt-1 flex gap-2">
                                <input v-model="ingForm.default_unit_cost" type="number" step="0.000001" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="ingForm.cost_unit" :disabled="holdUnits.length === 0" :title="t('item_kind.pack_sizes.unit')" data-test="cost-unit" class="w-24 shrink-0 rounded-lg border border-slate-200 bg-white px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                    <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ t('item_kind.per_unit', { unit: holdUnitLabel(u.value) }) }}</option>
                                </select>
                            </div>
                            <p v-if="ingModalErrors.default_unit_cost" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.default_unit_cost[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.min_stock_threshold') }}</span>
                            <!-- A7 — typed in any unit of the kind (5 kg), kept in the stored unit. -->
                            <div class="mt-1 flex gap-2">
                                <input v-model="ingForm.min_stock_threshold" type="number" step="0.0001" min="0" placeholder="—" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="ingForm.min_stock_unit" :disabled="holdUnits.length === 0" :title="t('item_kind.pack_sizes.unit')" data-test="min-stock-unit" class="w-24 shrink-0 rounded-lg border border-slate-200 bg-white px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                    <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ holdUnitLabel(u.value) }}</option>
                                </select>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.min_stock_threshold_hint') }}</p>
                            <p v-if="ingModalErrors.min_stock_threshold" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.min_stock_threshold[0] }}</p>
                        </label>
                    </div>
                    <!-- Phase A — piece unit (Additions §2.3), now the COUNT
                         CONTAINER. Label + ratio are a pair: both set = staff
                         count and receive in containers, both blank = not.
                         LAUNCH item kind, A5 — the ratio is typed as what the
                         container holds ("bottle holds 1.5 l", a unit of the
                         kind), not "1500 ml per piece"; the portal converts it
                         to units_per_piece in the stored unit. -->
                    <fieldset class="rounded-lg border border-amber-200 bg-amber-50/40 p-3" data-test="count-container">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Package class="me-1 inline size-3.5 text-amber-600" />
                            {{ t('item_kind.container.title') }}
                        </legend>
                        <p class="mb-2 text-xs text-slate-500">{{ t('item_kind.container.hint') }}</p>
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="block min-w-[8rem] flex-1">
                                <span class="text-sm font-medium text-slate-700">{{ t('item_kind.container.label') }}</span>
                                <input v-model="ingForm.piece_unit_label" type="text" maxlength="32" :placeholder="t('item_kind.container.label_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block w-36">
                                <span class="text-sm font-medium text-slate-700">{{ t('item_kind.container.label_ar') }}</span>
                                <input v-model="ingForm.piece_unit_label_ar" type="text" dir="rtl" maxlength="32" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <span class="pb-3 text-sm font-medium text-slate-600">{{ t('item_kind.holds') }}</span>
                            <label class="block w-28">
                                <span class="text-sm font-medium text-slate-700">{{ t('item_kind.pack_sizes.amount') }}</span>
                                <input v-model="ingForm.container_amount" type="number" step="0.0001" min="0" inputmode="decimal" placeholder="1.5" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block w-28">
                                <span class="text-sm font-medium text-slate-700">{{ t('item_kind.pack_sizes.unit') }}</span>
                                <select v-model="ingForm.container_unit" :disabled="holdUnits.length === 0" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                    <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ holdUnitLabel(u.value) }}</option>
                                </select>
                            </label>
                        </div>
                        <p v-if="ingModalErrors.piece_unit_label" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.piece_unit_label[0] }}</p>
                        <p v-if="ingModalErrors.units_per_piece" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.units_per_piece[0] }}</p>
                        <label class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input v-model="ingForm.allow_fractional_pieces" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            {{ t('inventory.piece.allow_fractional') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.piece.allow_fractional_hint') }}</p>
                    </fieldset>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <Truck class="me-1 inline size-3" />
                            {{ t('inventory.fields.primary_supplier') }}
                        </span>
                        <select v-model="ingForm.primary_supplier_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="null">{{ t('inventory.fields.primary_supplier_none') }}</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
                        </select>
                    </label>
                    <label v-if="ingModalMode === 'edit'" class="block max-w-xs">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.status') }}</span>
                        <select v-model="ingForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('inventory.statuses.active') }}</option>
                            <option value="inactive">{{ t('inventory.statuses.inactive') }}</option>
                        </select>
                    </label>

                    <!-- v2 #13 — Alternate units. Edit-mode only (needs a
                         saved ingredient uuid). Each row persists via its
                         own CRUD endpoint, so add / save-factor / delete
                         hit the API immediately. `factor` stays a string. -->
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Boxes class="me-1 inline size-3.5 text-amber-600" />
                            {{ ingModalMode === 'edit' ? t('item_kind.pack_sizes.title_edit') : t('item_kind.pack_sizes.title') }}
                        </legend>

                        <!-- LAUNCH item kind, A3 — "How do you buy it?" on a new
                             ingredient: optional pack sizes sent with it. -->
                        <div v-if="ingModalMode !== 'edit'" data-test="pack-sizes-create">
                            <p class="mb-2 text-xs text-slate-500">{{ t('item_kind.pack_sizes.hint') }}</p>
                            <ul v-if="packSizeDrafts.length > 0" class="mb-2 space-y-2">
                                <li v-for="(pack, i) in packSizeDrafts" :key="i" class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2" data-test="pack-size-draft">
                                    <label class="block min-w-[8rem] flex-1">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name') }} *</span>
                                        <input v-model="pack.name" type="text" maxlength="32" :placeholder="t('item_kind.pack_sizes.name_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </label>
                                    <label class="block w-32">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name_ar') }}</span>
                                        <input v-model="pack.name_ar" type="text" dir="rtl" maxlength="32" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </label>
                                    <span class="pb-2 text-sm font-medium text-slate-600">{{ t('item_kind.holds') }}</span>
                                    <label class="block w-24">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.amount') }} *</span>
                                        <input v-model="pack.amount" type="number" step="0.0001" min="0" inputmode="decimal" placeholder="12" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </label>
                                    <label class="block w-24">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.unit') }}</span>
                                        <select v-model="pack.unit" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                            <option v-for="u in holdUnits" :key="u.value" :value="u.value">{{ holdUnitLabel(u.value) }}</option>
                                        </select>
                                    </label>
                                    <button type="button" class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50" :title="t('item_kind.pack_sizes.remove')" @click="removePackSizeDraft(i)">
                                        <Trash2 class="size-4" />
                                    </button>
                                    <p v-if="packSizeError(i)" class="basis-full text-[11px] text-rose-600">{{ packSizeError(i) }}</p>
                                </li>
                            </ul>
                            <button
                                type="button"
                                :disabled="!ingKind"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
                                data-test="add-pack-size"
                                @click="addPackSizeDraft"
                            >
                                <Plus class="size-3.5" />
                                {{ t('item_kind.pack_sizes.add') }}
                            </button>
                            <p v-if="!ingKind" class="mt-1 text-[11px] text-slate-500">{{ t('item_kind.pack_sizes.choose_kind_first') }}</p>
                        </div>

                        <!-- LAUNCH item kind, A4 — the item's PACK SIZES (once the
                             "Alternate units"): each saved row reads "holds 12 l"
                             and is edited as holds [amount] [unit]; no factor is
                             typed. Each row persists through its own endpoint. -->
                        <template v-else>
                            <p class="mb-2 text-xs text-slate-500">{{ t('item_kind.pack_sizes.edit_hint') }}</p>

                            <!-- Section-level error banner (rose). -->
                            <div v-if="altUnitsError" class="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                                {{ altUnitsError }}
                            </div>

                            <div v-if="altUnitsLoading" class="text-xs text-slate-500">{{ t('common.loading') }}</div>

                            <template v-else>
                                <div v-if="altUnits.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                    {{ t('item_kind.pack_sizes.empty') }}
                                </div>
                                <ul v-else class="space-y-2" data-test="pack-sizes-edit">
                                    <li
                                        v-for="unit in altUnits"
                                        :key="unit.uuid"
                                        class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2"
                                    >
                                        <label class="block flex-1 min-w-[8rem]">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name') }}</span>
                                            <input
                                                :value="unit.name"
                                                type="text"
                                                readonly
                                                :title="t('item_kind.pack_sizes.name_immutable_hint')"
                                                class="mt-1 w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-sm text-slate-600"
                                            >
                                            <span class="mt-0.5 block text-[11px] text-slate-500" data-test="pack-size-holds">{{ packHoldsText(unit) }}</span>
                                        </label>
                                        <label v-if="altUnitDrafts[unit.uuid]" class="block w-32">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name_ar') }}</span>
                                            <input
                                                v-model="altUnitDrafts[unit.uuid].name_ar"
                                                type="text"
                                                dir="rtl"
                                                :disabled="!canManage"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50"
                                            >
                                        </label>
                                        <template v-if="altUnitDrafts[unit.uuid]">
                                            <span class="pb-2 text-sm font-medium text-slate-600">{{ t('item_kind.holds') }}</span>
                                            <label class="block w-24">
                                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.amount') }}</span>
                                                <input
                                                    v-model="altUnitDrafts[unit.uuid].amount"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    inputmode="decimal"
                                                    :disabled="!canManage"
                                                    class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50"
                                                >
                                            </label>
                                            <label class="block w-24">
                                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.unit') }}</span>
                                                <select v-model="altUnitDrafts[unit.uuid].unit" :disabled="!canManage" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50">
                                                    <option v-for="u in savedHoldUnits" :key="u.value" :value="u.value">{{ holdUnitLabel(u.value) }}</option>
                                                </select>
                                            </label>
                                        </template>
                                        <div v-if="canManage" class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                :disabled="altUnitBusyUuid === unit.uuid"
                                                class="inline-flex h-9 items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-2.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-wait disabled:opacity-60"
                                                @click="saveAltUnit(unit)"
                                            >
                                                <Check class="size-3.5" />
                                                {{ altUnitBusyUuid === unit.uuid ? t('inventory.alt_units.saving') : t('inventory.alt_units.save') }}
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="altUnitBusyUuid === unit.uuid"
                                                class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60"
                                                :title="t('item_kind.pack_sizes.remove')"
                                                @click="removeAltUnit(unit)"
                                            >
                                                <Trash2 class="size-4" />
                                            </button>
                                        </div>
                                        <p v-if="altUnitError(unit.uuid)" class="basis-full text-[11px] text-rose-600">{{ altUnitError(unit.uuid) }}</p>
                                    </li>
                                </ul>

                                <!-- Add-new row — manage-gated. -->
                                <div v-if="canManage" class="mt-3 flex flex-wrap items-end gap-2 rounded border border-teal-100 bg-teal-50/40 p-2" data-test="pack-size-new">
                                    <label class="block flex-1 min-w-[8rem]">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name') }} *</span>
                                        <input
                                            v-model="altUnitNew.name"
                                            type="text"
                                            maxlength="32"
                                            :placeholder="t('item_kind.pack_sizes.name_placeholder')"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                    </label>
                                    <label class="block w-32">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.name_ar') }}</span>
                                        <input
                                            v-model="altUnitNew.name_ar"
                                            type="text"
                                            dir="rtl"
                                            maxlength="32"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                    </label>
                                    <span class="pb-2 text-sm font-medium text-slate-600">{{ t('item_kind.holds') }}</span>
                                    <label class="block w-24">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.amount') }} *</span>
                                        <input
                                            v-model="altUnitNew.amount"
                                            type="number"
                                            step="0.0001"
                                            min="0"
                                            inputmode="decimal"
                                            placeholder="12"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                    </label>
                                    <label class="block w-24">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('item_kind.pack_sizes.unit') }}</span>
                                        <select v-model="altUnitNew.unit" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                            <option v-for="u in savedHoldUnits" :key="u.value" :value="u.value">{{ holdUnitLabel(u.value) }}</option>
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        :disabled="altUnitNewBusy || !altUnitNew.name.trim() || String(altUnitNew.amount).trim() === ''"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="addAltUnit"
                                    >
                                        <Plus class="size-3.5" />
                                        {{ altUnitNewBusy ? t('inventory.alt_units.saving') : t('item_kind.pack_sizes.add') }}
                                    </button>
                                    <p v-if="altUnitError('')" class="basis-full text-[11px] text-rose-600">{{ altUnitError('') }}</p>
                                </div>
                            </template>
                        </template>
                    </fieldset>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="ing-modal-form" :disabled="ingModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ ingModalBusy ? t('inventory.ing_modal.submitting') : t('inventory.ing_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== SUPPLIER MODAL ================== -->
        <BaseModal
            v-if="supModalOpen"
            :title="supModalMode === 'create' ? t('inventory.sup_modal.create_title') : t('inventory.sup_modal.edit_title')"
            size="md"
            :loading="supModalBusy"
            @close="supModalOpen = false"
        >
                <form id="sup-modal-form" class="space-y-4" @submit.prevent="submitSupplier">
                    <div v-if="supModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ supModalError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name') }} *</span>
                        <input v-model="supForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="supModalErrors.name" class="mt-1 text-xs text-rose-600">{{ supModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.contact') }}</span>
                        <input v-model="supForm.contact" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                        <textarea v-model="supForm.notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                    </label>
                    <label v-if="supModalMode === 'edit'" class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.status') }}</span>
                        <select v-model="supForm.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="active">{{ t('inventory.statuses.active') }}</option>
                            <option value="inactive">{{ t('inventory.statuses.inactive') }}</option>
                        </select>
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="sup-modal-form" :disabled="supModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ supModalBusy ? t('inventory.sup_modal.submitting') : t('inventory.sup_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== ADJUST MODAL ================== -->
        <BaseModal
            v-if="adjustOpen && adjustTarget.ingredient"
            size="md"
            :loading="adjustBusy"
            @close="adjustOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.adjust_modal.title', { ingredient: adjustTarget.ingredient.name }) }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('inventory.adjust_modal.subtitle') }}</p>
            </template>
                <form id="adjust-modal-form" class="space-y-4" @submit.prevent="submitAdjust">
                    <div v-if="adjustError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ adjustError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            {{ t('inventory.fields.signed_quantity') }} *
                        </span>
                        <div class="mt-1 flex gap-2">
                            <input v-model="adjustForm.signed_quantity" required type="number" step="0.0001" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <select v-model="adjustForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <!-- PD4 — base + custom alt + auto metric siblings. -->
                                <option v-for="u in ingredientUnitOptions(adjustTarget.ingredient, locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                            </select>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.signed_quantity_hint') }}</p>
                        <p v-if="adjustErrors.signed_quantity" class="mt-1 text-xs text-rose-600">{{ adjustErrors.signed_quantity[0] }}</p>
                        <p v-if="adjustErrors.unit" class="mt-1 text-xs text-rose-600">{{ adjustErrors.unit[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.note_required') }} *</span>
                        <textarea v-model="adjustForm.note" required rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.note_required_hint') }}</p>
                        <p v-if="adjustErrors.note" class="mt-1 text-xs text-rose-600">{{ adjustErrors.note[0] }}</p>
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="adjustOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="adjust-modal-form" :disabled="adjustBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ adjustBusy ? t('inventory.adjust_modal.submitting') : t('inventory.adjust_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== RESTOCK MODAL ================== -->
        <BaseModal
            v-if="restockOpen"
            size="md"
            :loading="restockBusy"
            @close="restockOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock_modal.title', { ingredient: restockTarget.ingredient?.name ?? '' }) }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('inventory.restock_modal.subtitle') }}</p>
            </template>
                <form id="restock-modal-form" class="space-y-4" @submit.prevent="submitRestock">
                    <div v-if="restockError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ restockError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.ingredient') }} *</span>
                        <select v-model="restockTarget.ingredient" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option v-for="ing in ingredients" :key="ing.id" :value="ing">{{ ing.name }}</option>
                        </select>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.quantity') }} *</span>
                            <div class="mt-1 flex gap-2">
                                <input v-model="restockForm.quantity" required type="number" step="0.0001" min="0.0001" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="restockForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <!-- PD4 — base + custom alt + auto metric siblings. -->
                                    <option v-for="u in ingredientUnitOptions(restockTarget.ingredient, locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                </select>
                            </div>
                            <p v-if="restockErrors.quantity" class="mt-1 text-xs text-rose-600">{{ restockErrors.quantity[0] }}</p>
                            <p v-if="restockErrors.unit" class="mt-1 text-xs text-rose-600">{{ restockErrors.unit[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.unit_cost_override') }}</span>
                            <input v-model="restockForm.unit_cost" type="number" step="0.000001" min="0" :placeholder="restockTarget.ingredient?.default_unit_cost ?? '—'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.unit_cost_hint') }}</p>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            <Truck class="me-1 inline size-3" />
                            {{ t('inventory.fields.supplier') }}
                        </span>
                        <select v-model="restockForm.supplier_uuid" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.fields.primary_supplier_none') }}</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.uuid">{{ sup.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                        <textarea v-model="restockForm.note" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="restockOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="restock-modal-form" :disabled="restockBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ restockBusy ? t('inventory.restock_modal.submitting') : t('inventory.restock_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE A — PURCHASE MODAL ================== -->
        <BaseModal
            v-if="purchaseOpen"
            size="lg"
            :loading="purchaseBusy"
            @close="purchaseOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.purchase_modal.title') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('inventory.purchase_modal.subtitle') }}</p>
            </template>
            <form id="purchase-modal-form" class="space-y-4" @submit.prevent="submitPurchase">
                <div v-if="purchaseError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ purchaseError }}
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.ingredient') }} *</span>
                    <select v-model="purchaseTarget.ingredient" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="ing in ingredients" :key="ing.id" :value="ing">{{ isArabic && ing.name_ar ? ing.name_ar : ing.name }}</option>
                    </select>
                </label>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            {{ pieceLabelFor(purchaseTarget.ingredient) !== null
                                ? t('inventory.purchase_modal.pieces', { label: pieceLabelFor(purchaseTarget.ingredient) ?? '' })
                                : t('inventory.purchase_modal.pieces_generic') }}
                        </span>
                        <input v-model="purchaseForm.pieces" type="number" :step="purchaseTarget.ingredient?.allow_fractional_pieces === false ? '1' : '0.0001'" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="purchaseErrors.pieces" class="mt-1 text-xs text-rose-600">{{ purchaseErrors.pieces[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.purchase_modal.units', { unit: unitShort(purchaseTarget.ingredient?.unit ?? null) }) }}</span>
                        <input v-model="purchaseForm.units" type="number" step="0.0001" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.purchase_modal.units_hint') }}</p>
                        <p v-if="purchaseErrors.units" class="mt-1 text-xs text-rose-600">{{ purchaseErrors.units[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.purchase_modal.total_paid') }} (OMR) *</span>
                        <input v-model="purchaseForm.total_paid" required type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="purchaseErrors.total_paid" class="mt-1 text-xs text-rose-600">{{ purchaseErrors.total_paid[0] }}</p>
                    </label>
                </div>
                <!-- Derived preview — total base units + unit cost. -->
                <div v-if="purchasePreview.units !== null" class="rounded-lg border border-teal-100 bg-teal-50/60 px-3 py-2 text-xs font-medium text-teal-800">
                    {{ t('inventory.purchase_modal.preview', {
                        units: purchasePreview.units.toFixed(3),
                        unit: unitShort(purchaseTarget.ingredient?.unit ?? null),
                        cost: purchasePreview.unitCost !== null ? purchasePreview.unitCost.toFixed(6) : '—',
                    }) }}
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">
                        <Truck class="me-1 inline size-3" />
                        {{ t('inventory.fields.supplier') }}
                    </span>
                    <select v-model="purchaseForm.supplier_uuid" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option value="">{{ t('inventory.fields.primary_supplier_none') }}</option>
                        <option v-for="sup in suppliers" :key="sup.id" :value="sup.uuid">{{ sup.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                    <textarea v-model="purchaseForm.note" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="purchaseOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="purchase-modal-form" :disabled="purchaseBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ purchaseBusy ? t('inventory.purchase_modal.submitting') : t('inventory.purchase_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE A — DAY-END COUNT MODAL ================== -->
        <BaseModal
            v-if="countOpen"
            size="xl"
            :loading="countBusy"
            @close="countOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.counts.modal.title', { branch: selectedBranchName }) }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ t('inventory.counts.modal.subtitle') }}</p>
            </template>
            <form id="count-modal-form" class="space-y-4" @submit.prevent="submitCount">
                <div v-if="countError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ countError }}
                </div>
                <div v-if="countRows.length === 0" class="rounded border border-dashed border-slate-200 p-6 text-center text-sm italic text-slate-500">
                    {{ t('inventory.counts.modal.no_stock') }}
                </div>
                <!-- LAUNCH-P2 P2-6 — a BLIND count: what is on the shelf, never the books. -->
                <p v-if="countRows.length > 0" class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600" data-test="blind-count-hint">{{ t('inventory.counts.modal.blind_hint') }}</p>
                <div v-if="countRows.length > 0" class="max-h-96 overflow-y-auto rounded-lg border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.modal.counted') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(r, i) in countRows" :key="r.ingredient.uuid">
                                <td class="px-4 py-2.5">
                                    <span class="block font-medium text-slate-800">{{ isArabic && r.ingredient.name_ar ? r.ingredient.name_ar : r.ingredient.name }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-end">
                                    <!-- LAUNCH item kind, A7 — counted in any unit the item knows:
                                         kg/g or l/ml, a pack size, or the count container. -->
                                    <div class="inline-flex items-center gap-2">
                                        <input
                                            v-model="countRows[i].counted"
                                            type="number"
                                            :step="countsPieces(r) && r.ingredient.allow_fractional_pieces === false ? '1' : 'any'"
                                            min="0"
                                            :placeholder="t('inventory.counts.modal.skip_placeholder')"
                                            class="w-28 rounded-lg border border-slate-200 px-2.5 py-1.5 text-end text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                        <select v-model="countRows[i].unit" :title="t('item_kind.count_unit')" data-test="count-unit" class="w-36 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                            <option v-for="u in ingredientUnitOptions(r.ingredient, locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.notes') }}</span>
                    <textarea v-model="countNote" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
            </form>
            <template #footer>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs text-slate-500">{{ t('inventory.counts.modal.filled', { filled: countFilledRows, total: countRows.length }) }}</span>
                    <div class="flex gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="countOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" form="count-modal-form" :disabled="countBusy || countFilledRows === 0" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ countBusy ? t('inventory.counts.modal.submitting') : t('inventory.counts.modal.submit') }}
                        </button>
                    </div>
                </div>
            </template>
        </BaseModal>

        <!-- ================== DELETE CONFIRMS ================== -->
        <BaseModal
            v-if="ingDeleteTarget"
            :title="t('inventory.delete_ing_dialog.title')"
            size="md"
            :loading="deleting"
            @close="ingDeleteTarget = null"
        >
            <div class="text-sm text-slate-700">{{ t('inventory.delete_ing_dialog.body', { name: ingDeleteTarget.name }) }}</div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteIngredient">
                        {{ deleting ? t('inventory.delete_ing_dialog.submitting') : t('inventory.delete_ing_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <BaseModal
            v-if="supDeleteTarget"
            :title="t('inventory.delete_sup_dialog.title')"
            size="md"
            :loading="deleting"
            @close="supDeleteTarget = null"
        >
            <div class="text-sm text-slate-700">{{ t('inventory.delete_sup_dialog.body', { name: supDeleteTarget.name }) }}</div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteSupplier">
                        {{ deleting ? t('inventory.delete_sup_dialog.submitting') : t('inventory.delete_sup_dialog.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — RECORD WASTE MODAL ================== -->
        <BaseModal
            v-if="wasteOpen"
            :title="t('inventory.waste.modal.title', { branch: selectedBranchName })"
            size="xl"
            :loading="wasteBusy"
            @close="wasteOpen = false"
        >
                <form id="waste-modal-form" class="space-y-4" @submit.prevent="submitRecordWaste">
                    <div v-if="wasteError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ wasteError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.ingredient') }} *</span>
                        <select v-model="wasteForm.ingredient_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.waste.modal.ingredient_placeholder') }}</option>
                            <option v-for="i in ingredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }} ({{ i.unit }})</option>
                            <!-- LAUNCH-P3 P3-4 — a prep item's waste is the waste of its raw ingredients. -->
                            <optgroup v-if="prepIngredients.length > 0" :label="t('prep_items.optgroup')" data-test="waste-prep-items">
                                <option v-for="i in prepIngredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }} ({{ i.unit }})</option>
                            </optgroup>
                        </select>
                        <p v-if="wasteErrors.ingredient_uuid" class="mt-1 text-xs text-rose-600">{{ wasteErrors.ingredient_uuid[0] }}</p>
                    </label>
                    <p v-if="wasteIsPrep" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-800" data-test="waste-prep-hint">{{ t('prep_items.waste_hint') }}</p>
                    <div v-if="wasteForm.ingredient_uuid && !wasteIsPrep" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        {{ t('inventory.waste.modal.current_balance') }}:
                        <span class="font-semibold text-slate-900">{{ wasteCurrentBalance === '—' ? '—' : qty(wasteCurrentBalance, wasteIngredient?.unit) }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.quantity') }} *</span>
                            <div class="mt-1 flex gap-2">
                                <input v-model="wasteForm.quantity" type="number" step="0.0001" min="0.0001" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="wasteForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <!-- PD4 — base + custom alt + auto metric siblings. -->
                                    <option v-for="u in ingredientUnitOptions(wasteIngredient, locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                </select>
                            </div>
                            <p v-if="wasteErrors.quantity" class="mt-1 text-xs text-rose-600">{{ wasteErrors.quantity[0] }}</p>
                            <p v-if="wasteErrors.unit" class="mt-1 text-xs text-rose-600">{{ wasteErrors.unit[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.reason') }} *</span>
                            <select v-model="wasteForm.reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm">
                                <option v-for="r in wasteReasons" :key="r" :value="r">{{ t(`inventory.waste.reasons.${r}`) }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.notes') }}<span v-if="wasteForm.reason === 'other'"> *</span></span>
                        <textarea v-model="wasteForm.notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                        <p v-if="wasteForm.reason === 'other'" class="mt-1 text-xs text-amber-700 font-semibold">{{ t('inventory.waste.modal.notes_help_other') }}</p>
                        <p v-if="wasteErrors.notes" class="mt-1 text-xs text-rose-600">{{ wasteErrors.notes[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.occurred_at') }}</span>
                        <input v-model="wasteForm.occurred_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.waste.modal.occurred_at_help') }}</p>
                    </label>
                    <div v-if="wasteForm.ingredient_uuid && wasteForm.quantity" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ t('inventory.waste.modal.cost_preview') }}</p>
                        <p class="mt-0.5 text-base font-bold tabular-nums text-amber-900">{{ wasteCostPreview }} <span class="text-[10px] font-normal text-amber-700">OMR</span></p>
                        <p class="mt-0.5 text-[10px] text-amber-700">{{ t('inventory.waste.modal.cost_preview_hint') }}</p>
                    </div>
                    <!-- LAUNCH-P3 fix order 1, K3 — sell-but-warn: a warning, never a block. -->
                    <div v-if="wasteInsufficient" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800" data-test="waste-insufficient">
                        <AlertTriangle class="me-1 inline size-3.5" />
                        {{ t('inventory.waste.modal.insufficient_warning') }}
                    </div>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="wasteOpen = false">
                        {{ t('inventory.waste.modal.cancel') }}
                    </button>
                    <button type="submit" form="waste-modal-form" :disabled="wasteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ wasteBusy ? t('inventory.waste.modal.submitting') : t('inventory.waste.modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — CREATE/EDIT RESTOCK REQUEST MODAL ================== -->
        <BaseModal
            v-if="restockModalOpen"
            :title="restockModalMode === 'create' ? t('inventory.restock.create_modal.title_create') : t('inventory.restock.create_modal.title_edit')"
            size="2xl"
            :loading="restockModalBusy"
            @close="restockModalOpen = false"
        >
                <form id="restock-request-form" class="space-y-4" @submit.prevent="submitRestockModal">
                    <div v-if="restockModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ restockModalError }}
                    </div>
                    <label v-if="restockModalMode === 'create'" class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock.create_modal.branch') }} *</span>
                        <select v-model="restockForm2.branch_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option value="">{{ t('inventory.restock.create_modal.branch_placeholder') }}</option>
                            <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock.create_modal.note') }}</span>
                        <textarea v-model="restockForm2.note" rows="2" :placeholder="t('inventory.restock.create_modal.note_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                    </label>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="mb-3 text-sm font-semibold text-slate-700">{{ t('inventory.restock.create_modal.lines_header') }}</p>
                        <div class="space-y-2">
                            <div v-for="(line, idx) in restockForm2.lines" :key="idx" class="grid gap-2 rounded-lg bg-white p-3 shadow-sm sm:grid-cols-12">
                                <select v-model="line.ingredient_uuid" class="sm:col-span-4 rounded-lg border border-slate-200 px-2 py-2 text-sm" @change="line.unit = ''">
                                    <option value="">{{ t('inventory.restock.create_modal.ingredient_placeholder') }}</option>
                                    <option v-for="i in ingredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }} ({{ i.unit }})</option>
                                </select>
                                <input v-model="line.quantity" type="number" step="0.0001" min="0.0001" :placeholder="t('inventory.restock.create_modal.quantity')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm tabular-nums">
                                <select v-model="line.unit" :title="t('inventory.fields.unit')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                    <!-- PD4 — base + custom alt + auto metric siblings. -->
                                    <option v-for="u in ingredientUnitOptions(ingredientByUuid(line.ingredient_uuid), locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                </select>
                                <input v-model="line.note" type="text" :placeholder="t('inventory.restock.create_modal.line_note')" class="sm:col-span-3 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                <button type="button" :title="t('inventory.restock.create_modal.remove_line')" class="sm:col-span-1 inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-2 py-2 text-rose-700 transition hover:bg-rose-100" @click="removeRestockLine(idx)">
                                    <Minus class="size-4" />
                                </button>
                            </div>
                        </div>
                        <button type="button" class="mt-3 inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="addRestockLine">
                            <Plus class="size-3.5" />
                            {{ t('inventory.restock.create_modal.add_line') }}
                        </button>
                        <p v-if="restockHasDuplicates" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                            <AlertTriangle class="me-1 inline size-3.5" />
                            {{ t('inventory.restock.create_modal.duplicate_warning') }}
                        </p>
                    </div>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="restockModalOpen = false">
                        {{ t('inventory.restock.create_modal.cancel') }}
                    </button>
                    <button type="submit" form="restock-request-form" :disabled="restockModalBusy || restockHasDuplicates" class="rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ restockModalBusy ? t('inventory.restock.create_modal.submitting') : (restockModalMode === 'create' ? t('inventory.restock.create_modal.submit_create') : t('inventory.restock.create_modal.submit_edit')) }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 6 — CREATE BRANCH TRANSFER MODAL ================== -->
        <BaseModal
            v-if="transferModalOpen"
            :title="t('inventory.transfers.create_modal.title')"
            size="2xl"
            :loading="transferModalBusy"
            @close="transferModalOpen = false"
        >
                <form id="branch-transfer-form" class="space-y-4" @submit.prevent="submitTransferModal">
                    <div v-if="transferModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ transferModalError }}
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.transfers.create_modal.from_branch') }} *</span>
                            <select v-model="transferForm.from_branch_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="">{{ t('inventory.transfers.create_modal.from_placeholder') }}</option>
                                <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.transfers.create_modal.to_branch') }} *</span>
                            <select v-model="transferForm.to_branch_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="">{{ t('inventory.transfers.create_modal.to_placeholder') }}</option>
                                <option v-for="b in branches" :key="b.uuid" :value="b.uuid" :disabled="b.uuid === transferForm.from_branch_uuid">{{ b.name }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.transfers.create_modal.note') }}</span>
                        <textarea v-model="transferForm.note" rows="2" :placeholder="t('inventory.transfers.create_modal.note_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                    </label>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="mb-3 text-sm font-semibold text-slate-700">{{ t('inventory.transfers.create_modal.lines_header') }}</p>
                        <div class="space-y-2">
                            <div v-for="(line, idx) in transferForm.lines" :key="idx" class="grid gap-2 rounded-lg bg-white p-3 shadow-sm sm:grid-cols-12">
                                <select v-model="line.ingredient_uuid" class="sm:col-span-6 rounded-lg border border-slate-200 px-2 py-2 text-sm" @change="line.unit = ''">
                                    <option value="">{{ t('inventory.transfers.create_modal.ingredient_placeholder') }}</option>
                                    <option v-for="i in ingredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }} ({{ i.unit }})</option>
                                </select>
                                <input v-model="line.quantity" type="number" step="0.0001" min="0.0001" :placeholder="t('inventory.transfers.create_modal.quantity')" class="sm:col-span-3 rounded-lg border border-slate-200 px-2 py-2 text-sm tabular-nums">
                                <select v-model="line.unit" :title="t('inventory.fields.unit')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                    <!-- PD4 — base + custom alt + auto metric siblings. -->
                                    <option v-for="u in ingredientUnitOptions(ingredientByUuid(line.ingredient_uuid), locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                </select>
                                <button type="button" :title="t('inventory.transfers.create_modal.remove_line')" class="sm:col-span-1 inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-2 py-2 text-rose-700 transition hover:bg-rose-100" @click="removeTransferLine(idx)">
                                    <Minus class="size-4" />
                                </button>
                            </div>
                        </div>
                        <button type="button" class="mt-3 inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="addTransferLine">
                            <Plus class="size-3.5" />
                            {{ t('inventory.transfers.create_modal.add_line') }}
                        </button>
                        <p v-if="transferHasDuplicates" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                            <AlertTriangle class="me-1 inline size-3.5" />
                            {{ t('inventory.transfers.create_modal.duplicate_warning') }}
                        </p>
                    </div>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="transferModalOpen = false">
                        {{ t('inventory.transfers.create_modal.cancel') }}
                    </button>
                    <button type="submit" form="branch-transfer-form" :disabled="transferModalBusy || transferHasDuplicates" class="rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ transferModalBusy ? t('inventory.transfers.create_modal.submitting') : t('inventory.transfers.create_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — SHOW RESTOCK REQUEST MODAL ================== -->
        <BaseModal
            v-if="showOpen && showTarget"
            size="2xl"
            @close="showOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.show_modal.title', { branch: showTarget.branch?.name ?? '—' }) }}</h2>
                <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="restockStatusBadgeClass(showTarget.status)">
                    {{ t(`inventory.restock.statuses.${showTarget.status}`) }}
                </span>
            </template>
                <div class="space-y-4">
                    <!-- Lifecycle timeline -->
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs">
                        <p class="mb-2 text-sm font-semibold text-slate-700">{{ t('inventory.restock.show_modal.lifecycle') }}</p>
                        <ul class="space-y-1 text-slate-600">
                            <li><span class="font-semibold text-slate-800">{{ t('inventory.restock.created_at') }}:</span> {{ formatDate(showTarget.created_at) }} {{ showTarget.requested_by ? `— ${showTarget.requested_by.name}` : '' }}</li>
                            <li v-if="showTarget.submitted_at"><span class="font-semibold text-slate-800">{{ t('inventory.restock.submitted_at') }}:</span> {{ formatDate(showTarget.submitted_at) }}</li>
                            <li v-if="showTarget.reviewed_at"><span class="font-semibold text-slate-800">{{ t('inventory.restock.reviewed_at') }}:</span> {{ formatDate(showTarget.reviewed_at) }} {{ showTarget.reviewed_by ? `— ${showTarget.reviewed_by.name}` : '' }}</li>
                            <li v-if="showTarget.fulfilled_at"><span class="font-semibold text-slate-800">{{ t('inventory.restock.fulfilled_at') }}:</span> {{ formatDate(showTarget.fulfilled_at) }}</li>
                            <li v-if="showTarget.review_note"><span class="font-semibold text-slate-800">{{ t('inventory.restock.review_note') }}:</span> {{ showTarget.review_note }}</li>
                            <li v-if="showTarget.note"><span class="font-semibold text-slate-800">{{ t('inventory.restock.note') }}:</span> {{ showTarget.note }}</li>
                        </ul>
                    </div>

                    <!-- Lines -->
                    <div>
                        <p class="mb-2 text-sm font-semibold text-slate-700">{{ t('inventory.restock.show_modal.lines_header') }}</p>
                        <div v-if="(showTarget.lines ?? []).length === 0" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            {{ t('inventory.restock.row.no_lines') }}
                        </div>
                        <table v-else class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.show_modal.ingredient') }}</th>
                                    <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.show_modal.requested') }}</th>
                                    <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.show_modal.allocated') }}</th>
                                    <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.show_modal.line_note') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="l in showTarget.lines" :key="l.id">
                                    <td class="px-3 py-2 font-medium text-slate-900">{{ l.ingredient ? (isArabic && l.ingredient.name_ar ? l.ingredient.name_ar : l.ingredient.name) : '—' }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ friendlyAmount(l.quantity_requested, l.unit_at_set).amount }} <span class="text-[10px] text-slate-500">{{ friendlyAmount(l.quantity_requested, l.unit_at_set).unit }}</span></td>
                                    <td class="px-3 py-2 text-end tabular-nums font-semibold text-emerald-700">{{ qty(l.quantity_allocated, l.unit_at_set) }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ l.note || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <template #footer>
                <div class="flex justify-end">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="showOpen = false">{{ t('inventory.restock.show_modal.close') }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — REVIEW (APPROVE/REJECT) MODAL ================== -->
        <BaseModal
            v-if="reviewOpen && reviewTarget"
            :title="reviewMode === 'approve' ? t('inventory.restock.review_modal.title_approve') : t('inventory.restock.review_modal.title_reject')"
            size="md"
            :loading="reviewBusy"
            @close="reviewOpen = false"
        >
                <form id="review-modal-form" class="space-y-3" @submit.prevent="submitReview">
                    <div v-if="reviewError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ reviewError }}
                    </div>
                    <p class="text-xs text-slate-600">
                        {{ reviewMode === 'approve' ? t('inventory.restock.review_modal.approve_hint') : t('inventory.restock.review_modal.reject_hint') }}
                    </p>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock.review_modal.note') }}{{ reviewMode === 'reject' ? ' *' : '' }}</span>
                        <textarea v-model="reviewNote" rows="3" :required="reviewMode === 'reject'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="reviewOpen = false">{{ t('inventory.restock.review_modal.cancel') }}</button>
                    <button type="submit" form="review-modal-form" :disabled="reviewBusy" :class="reviewMode === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'" class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-wait disabled:opacity-60">
                        {{ reviewBusy ? t('inventory.restock.review_modal.submitting') : (reviewMode === 'approve' ? t('inventory.restock.review_modal.submit_approve') : t('inventory.restock.review_modal.submit_reject')) }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — CANCEL MODAL ================== -->
        <BaseModal
            v-if="cancelOpen && cancelTarget"
            :title="t('inventory.restock.cancel_modal.title')"
            size="md"
            :loading="cancelBusy"
            @close="cancelOpen = false"
        >
                <form id="cancel-modal-form" class="space-y-3" @submit.prevent="submitCancel">
                    <div v-if="cancelError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ cancelError }}
                    </div>
                    <p class="text-xs text-slate-600">{{ t('inventory.restock.cancel_modal.hint') }}</p>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock.cancel_modal.note') }}</span>
                        <textarea v-model="cancelNote" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="cancelOpen = false">{{ t('inventory.restock.cancel_modal.back') }}</button>
                    <button type="submit" form="cancel-modal-form" :disabled="cancelBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60">
                        {{ cancelBusy ? t('inventory.restock.cancel_modal.submitting') : t('inventory.restock.cancel_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE 5c — ALLOCATE MODAL ================== -->
        <BaseModal
            v-if="allocateOpen && allocateTarget"
            size="xl"
            :loading="allocateBusy"
            @close="allocateOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.allocate_modal.title') }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ t('inventory.restock.allocate_modal.hint') }}</p>
            </template>
                <form id="allocate-modal-form" class="space-y-3" @submit.prevent="submitAllocate">
                    <div v-if="allocateError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ allocateError }}
                    </div>
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.allocate_modal.ingredient') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.allocate_modal.requested') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.allocate_modal.central') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.allocate_modal.allocated') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="l in allocateTarget.lines" :key="l.id">
                                <td class="px-3 py-2 font-medium text-slate-900">
                                    {{ l.ingredient ? (isArabic && l.ingredient.name_ar ? l.ingredient.name_ar : l.ingredient.name) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ friendlyAmount(l.quantity_requested, l.unit_at_set).amount }} <span class="text-[10px] text-slate-500">{{ friendlyAmount(l.quantity_requested, l.unit_at_set).unit }}</span></td>
                                <td class="px-3 py-2 text-end tabular-nums" :class="parseFloat(l.ingredient?.central_quantity ?? '0') < allocatedStored(l) ? 'font-semibold text-rose-600' : 'text-slate-700'">
                                    {{ l.ingredient?.central_quantity != null ? qty(l.ingredient.central_quantity, l.unit_at_set) : '—' }}
                                </td>
                                <!-- F5 — typed in any unit of the line's item (36 l, 3 crates). -->
                                <td class="px-3 py-2 text-end">
                                    <div class="inline-flex items-center gap-1.5">
                                        <input v-model="allocateOverrides[String(l.id)]" type="number" step="0.0001" min="0" class="w-24 rounded-lg border border-slate-200 px-2 py-1.5 text-sm tabular-nums text-end">
                                        <select v-model="allocateUnits[String(l.id)]" :title="t('inventory.fields.unit')" data-test="allocate-unit" class="w-32 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                            <option v-for="u in ingredientUnitOptions(restockLineIngredient(l), locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="allocateHasOver" class="rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                        <AlertTriangle class="me-1 inline size-3.5" />
                        {{ t('inventory.restock.allocate_modal.over_warning') }}
                    </p>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="allocateOpen = false">{{ t('inventory.restock.allocate_modal.cancel') }}</button>
                    <button type="submit" form="allocate-modal-form" :disabled="allocateBusy || allocateHasOver" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ allocateBusy ? t('inventory.restock.allocate_modal.submitting') : t('inventory.restock.allocate_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== PHASE A — RESOLVED-BY-PURCHASE MODAL ================== -->
        <BaseModal
            v-if="purchasedOpen && purchasedTarget"
            size="md"
            :loading="purchasedBusy"
            @close="purchasedOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.purchased_modal.title') }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ t('inventory.restock.purchased_modal.hint') }}</p>
            </template>
                <form id="purchased-modal-form" class="space-y-3" @submit.prevent="submitPurchased">
                    <div v-if="purchasedError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ purchasedError }}
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.purchased_modal.note_label') }}</span>
                        <input v-model="purchasedNote" type="text" maxlength="255" :placeholder="t('inventory.restock.purchased_modal.note_placeholder')" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    </label>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="purchasedOpen = false">{{ t('inventory.restock.purchased_modal.cancel') }}</button>
                    <button type="submit" form="purchased-modal-form" :disabled="purchasedBusy" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ purchasedBusy ? t('inventory.restock.purchased_modal.submitting') : t('inventory.restock.purchased_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================== SMART RESTOCK SUGGESTIONS MODAL ================== -->
        <BaseModal
            v-if="suggestOpen"
            size="4xl"
            :loading="suggestCreating"
            @close="suggestOpen = false"
        >
            <template #icon>
                <span class="grid size-9 place-items-center rounded-lg bg-indigo-50 text-indigo-600">
                    <Lightbulb class="size-5" />
                </span>
            </template>
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock_suggestions.title') }}</h2>
                <p class="mt-1 text-xs text-slate-600">{{ t('inventory.restock_suggestions.subtitle', { branch: selectedBranchName }) }}</p>
            </template>

            <div class="space-y-4">
                <div v-if="suggestError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ suggestError }}
                </div>

                <!-- Forecast knobs -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.window_days') }}</span>
                        <input v-model.number="suggestWindowDays" type="number" min="1" max="365" step="1" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.restock_suggestions.window_days_hint') }}</p>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.cover_days') }}</span>
                        <input v-model.number="suggestCoverDays" type="number" min="1" max="365" step="1" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.restock_suggestions.cover_days_hint') }}</p>
                    </label>
                </div>

                <div v-if="suggestLoading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="suggestLoaded && suggestRows.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center">
                    <CheckCircle2 class="mx-auto size-10 text-emerald-400" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.restock_suggestions.empty') }}</p>
                </div>
                <div v-else-if="suggestRows.length > 0" class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.include') }}</th>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.ingredient') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.current') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.avg_daily') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.target') }}</th>
                                <th class="px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.reason') }}</th>
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock_suggestions.suggested') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in suggestRows" :key="row.suggestion.ingredient_uuid" class="align-middle" :class="row.include ? '' : 'opacity-50'">
                                <td class="px-3 py-2">
                                    <input v-model="row.include" type="checkbox" class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </td>
                                <td class="px-3 py-2 font-medium text-slate-900">
                                    {{ row.suggestion.name }}
                                </td>
                                <!-- F5 — "24 l", not "24000.000 ml". -->
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ qty(row.suggestion.current_quantity, row.suggestion.unit) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ qty(row.suggestion.avg_daily_consumption, row.suggestion.unit) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ qty(row.suggestion.target_level, row.suggestion.unit) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="reasonBadgeClass(row.suggestion.reason)">
                                        {{ reasonLabel(row.suggestion.reason) }}
                                    </span>
                                </td>
                                <!-- F5 — typed in any unit of the item (the kind's units, pack sizes, container). -->
                                <td class="px-3 py-2 text-end">
                                    <div class="inline-flex items-center gap-1.5">
                                        <input v-model="row.qty" :disabled="!row.include" type="number" step="0.0001" min="0" class="w-24 rounded-lg border border-slate-200 px-2 py-1.5 text-sm tabular-nums text-end focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-400">
                                        <select v-model="row.unit" :disabled="!row.include" :title="t('inventory.fields.unit')" data-test="suggestion-unit" class="w-32 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm disabled:bg-slate-50 disabled:text-slate-400">
                                            <option v-for="u in ingredientUnitOptions(suggestionIngredient(row.suggestion), locale)" :key="u.value || 'base'" :value="u.value">{{ u.label }}</option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <label v-if="canCreateRestock && suggestRows.length > 0" class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock_suggestions.note') }}</span>
                    <textarea v-model="suggestNote" rows="2" :placeholder="t('inventory.restock_suggestions.note_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                </label>
                <p v-else-if="!canCreateRestock && suggestRows.length > 0" class="text-xs text-slate-500">
                    {{ t('inventory.restock_suggestions.read_only_note') }}
                </p>
            </div>

            <template #footer>
                <div class="flex items-center justify-between gap-2">
                    <span v-if="canCreateRestock && suggestRows.length > 0" class="text-xs font-medium text-slate-500">
                        {{ t('inventory.restock_suggestions.selected_count', { count: suggestSelectedCount }) }}
                    </span>
                    <span v-else></span>
                    <div class="flex gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="suggestOpen = false">
                            {{ t('inventory.restock_suggestions.close') }}
                        </button>
                        <button
                            v-if="canCreateRestock"
                            type="button"
                            :disabled="suggestCreating || suggestLoading || suggestSelectedCount === 0"
                            class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="submitSuggestions"
                        >
                            <ClipboardList class="size-4" />
                            {{ suggestCreating ? t('inventory.restock_suggestions.creating') : t('inventory.restock_suggestions.create') }}
                        </button>
                    </div>
                </div>
            </template>
        </BaseModal>

        <!-- PD3a — physical item create/edit -->
        <BaseModal
            v-if="physicalItemModalOpen"
            :title="physicalItemModalMode === 'create' ? t('inventory.physical_items.create_title') : t('inventory.physical_items.edit_title')"
            size="lg"
            :loading="physicalItemModalBusy"
            @close="physicalItemModalOpen = false"
        >
            <form id="physical-item-form" class="space-y-4" @submit.prevent="submitPhysicalItem">
                <div v-if="physicalItemModalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ physicalItemModalError }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name') }} *</span>
                        <input v-model="physicalItemForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="physicalItemModalErrors.name" class="mt-1 text-xs text-rose-600">{{ physicalItemModalErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.name_ar') }}</span>
                        <input v-model="physicalItemForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="physicalItemModalErrors.name_ar" class="mt-1 text-xs text-rose-600">{{ physicalItemModalErrors.name_ar[0] }}</p>
                    </label>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-700">{{ t('inventory.physical_items.kind_label') }}</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition" :class="physicalItemForm.purpose === 'packaging' ? 'border-teal-500 bg-teal-50/60 ring-2 ring-teal-100' : 'border-slate-200 hover:bg-slate-50'">
                            <input v-model="physicalItemForm.purpose" type="radio" value="packaging" class="mt-1 border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ t('inventory.physical_items.purposes.packaging') }}</span>
                                <span class="block text-xs text-slate-500">{{ t('inventory.physical_items.purpose_packaging_hint') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition" :class="physicalItemForm.purpose === 'general' ? 'border-teal-500 bg-teal-50/60 ring-2 ring-teal-100' : 'border-slate-200 hover:bg-slate-50'">
                            <input v-model="physicalItemForm.purpose" type="radio" value="general" class="mt-1 border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">{{ t('inventory.physical_items.purposes.general') }}</span>
                                <span class="block text-xs text-slate-500">{{ t('inventory.physical_items.purpose_general_hint') }}</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.physical_items.cost_label') }} (OMR)</span>
                        <input v-model="physicalItemForm.cost_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.physical_items.cost_hint') }}</p>
                        <p v-if="physicalItemModalErrors.cost_price" class="mt-1 text-xs text-rose-600">{{ physicalItemModalErrors.cost_price[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.physical_items.low_stock_label') }}</span>
                        <input v-model="physicalItemForm.low_stock_threshold" type="number" step="1" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="physicalItemModalErrors.low_stock_threshold" class="mt-1 text-xs text-rose-600">{{ physicalItemModalErrors.low_stock_threshold[0] }}</p>
                    </label>
                </div>
                <label v-if="physicalItemModalMode === 'edit'" class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                    <select v-model="physicalItemForm.status" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option value="active">{{ t('catalogue.statuses.active') }}</option>
                        <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                    </select>
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="physicalItemModalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="physical-item-form" :disabled="physicalItemModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                        {{ physicalItemModalBusy ? t('common.saving') : t('common.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- PD3a — physical-item delete confirm -->
        <BaseModal
            v-if="physicalItemDeleteTarget"
            :title="t('inventory.physical_items.delete_title')"
            size="md"
            :loading="physicalItemDeleting"
            @close="physicalItemDeleteTarget = null"
        >
            <p class="text-sm text-slate-700">{{ t('inventory.physical_items.delete_body', { name: physicalItemDeleteTarget.name }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="physicalItemDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="physicalItemDeleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60" @click="confirmDeletePhysicalItem">
                        {{ physicalItemDeleting ? t('common.deleting') : t('inventory.physical_items.delete') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- PD3a — physical-item stock (the product stock dialog: the rows
             share the piece-counting machinery, incl. PD2 cost->expense). -->
        <ProductStockDialog
            :open="physicalItemStockTarget !== null"
            :product-uuid="physicalItemStockTarget?.uuid ?? null"
            :product-name="physicalItemStockTarget?.name ?? ''"
            :can-manage="canManage"
            :cost-price="physicalItemStockTarget?.cost_price ?? null"
            @close="physicalItemStockTarget = null"
        />

        <!-- P-G4 — central ingredient warehouse dialog -->
        <IngredientStockDialog
            :open="warehouseDialogIngredient !== null"
            :ingredient-uuid="warehouseDialogIngredient?.uuid ?? null"
            :ingredient-name="warehouseDialogIngredient?.name ?? ''"
            :ingredient="warehouseDialogIngredient"
            :can-manage="canManage"
            :single-stock-in="singleStockIn"
            @close="warehouseDialogIngredient = null"
        />
    </MerchantLayout>
</template>

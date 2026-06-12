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
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import IngredientStockDialog from './IngredientStockDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    adjustStock,
    allocateRestockRequest,
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
    type RestockRequestStatus,
    type RestockSuggestion,
    type StockMovementType,
    type Supplier,
    type WasteReason,
    type WasteRecord,
} from '@/lib/api/inventory';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canViewInventory = computed(() => can(MerchantPermission.InventoryView));
const canManage = computed(() => can(MerchantPermission.InventoryManage));
// Phase 5c — split restock permissions: create on the requester
// side, review on the HQ side. Either one gives the user access
// to the Restock Requests tab (it's READ-also for InventoryView).
const canCreateRestock = computed(() => can(MerchantPermission.RestockRequestCreate));
const canReviewRestock = computed(() => can(MerchantPermission.RestockRequestReview));

type TabKey = 'ingredients' | 'suppliers' | 'stock' | 'movements' | 'waste' | 'stock_counts' | 'restock_requests' | 'transfers';
const activeTab = ref<TabKey>('ingredients');

// =================== Shared data =================================

const branches = ref<Branch[]>([]);
const selectedBranchUuid = ref<string | null>(null);

const ingredients = ref<Ingredient[]>([]);
const suppliers = ref<Supplier[]>([]);
const branchStock = ref<BranchStockRow[]>([]);
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
    unit: IngredientUnit;
    piece_unit_label: string;
    piece_unit_label_ar: string;
    units_per_piece: string;
    allow_fractional_pieces: boolean;
    default_unit_cost: string;
    min_stock_threshold: string;
    primary_supplier_id: number | null;
    status: InventoryStatus;
}>({
    name: '',
    name_ar: '',
    unit: 'kg',
    piece_unit_label: '',
    piece_unit_label_ar: '',
    units_per_piece: '',
    allow_fractional_pieces: true,
    default_unit_cost: '0.000',
    min_stock_threshold: '',
    primary_supplier_id: null,
    status: 'active',
});

const unitOptions: IngredientUnit[] = ['kg', 'g', 'l', 'ml', 'piece', 'pack', 'box'];

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
// New-row draft.
const altUnitNew = reactive<{ name: string; name_ar: string; factor: string }>({
    name: '',
    name_ar: '',
    factor: '',
});
const altUnitNewBusy = ref(false);
// Editable buffers for existing rows, keyed by unit uuid. Lets the
// user tweak factor / Arabic name without mutating the source list
// until they hit Save.
const altUnitDrafts = reactive<Record<string, { name_ar: string; factor: string }>>({});

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
interface CountRow {
    row: BranchStockRow;
    ingredient: Ingredient | null;
    counted: string;
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
        const response = await listBranchStock(selectedBranchUuid.value);
        branchStock.value = response.data;
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

async function bootstrap(): Promise<void> {
    loading.value = true;
    error.value = null;
    // Restock requests aren't branch-scoped on the API — load
    // them eagerly so the count badge on the tab is accurate
    // even before the user clicks the tab.
    await Promise.all([fetchBranches(), fetchIngredients(), fetchSuppliers(), fetchRestockRequests(), fetchBranchTransfers()]);
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
    ingForm.unit = 'kg';
    ingForm.piece_unit_label = '';
    ingForm.piece_unit_label_ar = '';
    ingForm.units_per_piece = '';
    ingForm.allow_fractional_pieces = true;
    ingForm.default_unit_cost = '0.000';
    ingForm.min_stock_threshold = '';
    ingForm.primary_supplier_id = null;
    ingForm.status = 'active';
    ingModalErrors.value = {};
    ingModalError.value = null;
    resetAltUnits();
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
    ingForm.units_per_piece = ingredient.units_per_piece ?? '';
    ingForm.allow_fractional_pieces = ingredient.allow_fractional_pieces;
    ingForm.default_unit_cost = ingredient.default_unit_cost;
    ingForm.min_stock_threshold = ingredient.min_stock_threshold ?? '';
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
    ingModalBusy.value = true;
    ingModalErrors.value = {};
    ingModalError.value = null;
    try {
        const payload = {
            name: ingForm.name.trim(),
            name_ar: ingForm.name_ar.trim() || null,
            unit: ingForm.unit,
            // Phase A — piece config travels as a pair (server enforces
            // both-or-neither); blanks become null = "not piece-tracked".
            piece_unit_label: ingForm.piece_unit_label.trim() || null,
            piece_unit_label_ar: ingForm.piece_unit_label_ar.trim() || null,
            units_per_piece: String(ingForm.units_per_piece).trim() === ''
                ? null
                : ingForm.units_per_piece,
            allow_fractional_pieces: ingForm.allow_fractional_pieces,
            default_unit_cost: ingForm.default_unit_cost,
            // The bound input is type="number", so Vue casts this to a
            // number as soon as the user types — String() keeps the
            // empty-check safe for both the number and blank-string cases.
            min_stock_threshold: String(ingForm.min_stock_threshold).trim() === ''
                ? null
                : ingForm.min_stock_threshold,
            primary_supplier_id: ingForm.primary_supplier_id ?? null,
        };
        if (ingModalMode.value === 'create') {
            await createIngredient(payload);
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
    altUnitNew.factor = '';
    altUnitNewBusy.value = false;
    for (const k of Object.keys(altUnitDrafts)) delete altUnitDrafts[k];
}

function seedAltUnits(units: IngredientAltUnit[]): void {
    resetAltUnits();
    altUnits.value = [...units].sort((a, b) => a.sort_order - b.sort_order);
    syncAltUnitDrafts();
}

// Mirror the source list into editable drafts (factor + Arabic
// name), so editing a row doesn't mutate the canonical data.
function syncAltUnitDrafts(): void {
    for (const k of Object.keys(altUnitDrafts)) delete altUnitDrafts[k];
    for (const u of altUnits.value) {
        altUnitDrafts[u.uuid] = { name_ar: u.name_ar ?? '', factor: u.factor };
    }
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
            err instanceof Error ? err.message : t('inventory.alt_units.errors.load_failed');
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
            // Send the raw string through — decimal(14,4) server-side.
            factor: altUnitNew.factor,
        });
        altUnitNew.name = '';
        altUnitNew.name_ar = '';
        altUnitNew.factor = '';
        await loadAltUnits(ingModalTarget.value.uuid);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            altUnitFieldErrors.value = { ...altUnitFieldErrors.value, '': err.payload.errors };
            altUnitsError.value = t('inventory.validation_summary');
        } else {
            altUnitsError.value = altUnitErrorMessage(err, 'inventory.alt_units.errors.save_failed');
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
        // name is IMMUTABLE — only factor + Arabic name go up.
        await updateIngredientUnit(ingModalTarget.value.uuid, unit.uuid, {
            name_ar: draft.name_ar.trim() || null,
            factor: draft.factor,
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
            altUnitsError.value = altUnitErrorMessage(err, 'inventory.alt_units.errors.save_failed');
        }
    } finally {
        altUnitBusyUuid.value = null;
    }
}

async function removeAltUnit(unit: IngredientAltUnit): Promise<void> {
    if (!ingModalTarget.value) return;
    if (!window.confirm(t('inventory.alt_units.delete_confirm'))) return;
    altUnitBusyUuid.value = unit.uuid;
    altUnitsError.value = null;
    try {
        await deleteIngredientUnit(ingModalTarget.value.uuid, unit.uuid);
        await loadAltUnits(ingModalTarget.value.uuid);
    } catch (err) {
        altUnitsError.value = altUnitErrorMessage(err, 'inventory.alt_units.errors.delete_failed');
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
    countRows.value = branchStock.value.map((row) => ({
        row,
        ingredient: ingredients.value.find((i) => i.id === row.ingredient_id) ?? null,
        counted: '',
    }));
    countNote.value = '';
    countError.value = null;
    countOpen.value = true;
}

const countFilledRows = computed<number>(() =>
    countRows.value.filter((r) => String(r.counted).trim() !== '').length,
);

async function submitCount(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    const lines: StockCountLinePayload[] = [];
    for (const r of countRows.value) {
        if (String(r.counted).trim() === '' || r.ingredient === null) continue;
        // Piece-tracked ingredients are counted in PIECES; everything
        // else directly in the base unit.
        if (pieceLabelFor(r.ingredient) !== null) {
            lines.push({ ingredient_uuid: r.ingredient.uuid, counted_pieces: r.counted });
        } else {
            lines.push({ ingredient_uuid: r.ingredient.uuid, counted_units: r.counted });
        }
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
        const varianceLines = response.data.lines.filter((l) => Number(l.variance_units) !== 0).length;
        success.value = varianceLines > 0
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
function countShortfallValue(count: { lines: { variance_value: string }[] }): number {
    return count.lines.reduce((sum, l) => {
        const v = Number(l.variance_value);
        return Number.isFinite(v) && v < 0 ? sum + v : sum;
    }, 0);
}

// =================== Helpers =====================================

function unitLabel(unit: IngredientUnit | null): string {
    if (!unit) return '';
    return t(`inventory.units.${unit}`);
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
    if (!ingredient || selected.trim() === '') return qty;
    const alt = (ingredient.alt_units ?? []).find((u) => u.name === selected);
    if (!alt) return qty;
    const factor = parseFloat(alt.factor);
    if (!Number.isFinite(factor)) return qty;
    return qty * factor;
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
    const ing = ingredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid);
    if (!ing) return '—';
    const row = branchStock.value.find((r) => r.ingredient_id === ing.id);
    return row?.quantity ?? '0.000';
});

const wasteInsufficient = computed<boolean>(() => {
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
    return ingredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid) ?? null;
});

async function submitRecordWaste(): Promise<void> {
    if (selectedBranchUuid.value === null) return;
    wasteBusy.value = true;
    wasteErrors.value = {};
    wasteError.value = null;
    try {
        await recordWaste(selectedBranchUuid.value, {
            ingredient_uuid: wasteForm.ingredient_uuid,
            quantity: wasteForm.quantity,
            reason: wasteForm.reason,
            notes: wasteForm.notes.trim() || null,
            occurred_at: wasteForm.occurred_at.trim() || null,
            unit: wireUnit(wasteForm.unit),
        });
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

function openAllocate(req: RestockRequest): void {
    allocateTarget.value = req;
    // Default every line's allocate input to the requested
    // quantity. The user can then over-write the ones they're
    // sending less of.
    Object.keys(allocateOverrides).forEach((k) => delete allocateOverrides[k]);
    for (const line of req.lines ?? []) {
        allocateOverrides[String(line.id)] = line.quantity_requested;
    }
    allocateError.value = null;
    allocateOpen.value = true;
}

const allocateHasOver = computed<boolean>(() => {
    if (!allocateTarget.value) return false;
    for (const line of allocateTarget.value.lines ?? []) {
        const allocated = parseFloat(allocateOverrides[String(line.id)] ?? '0');
        const requested = parseFloat(line.quantity_requested);
        if (allocated > requested) return true;
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
        const allocations: Record<number, string> = {};
        for (const line of allocateTarget.value.lines ?? []) {
            allocations[line.id] = allocateOverrides[String(line.id)] ?? line.quantity_requested;
        }
        await allocateRestockRequest(allocateTarget.value.uuid, { allocations });
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
        suggestRows.value = response.data.map((s) => ({
            suggestion: s,
            include: true,
            // Keep the server's decimal:3 string verbatim — never
            // round-trip through a Number.
            qty: s.suggested_quantity,
        }));
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
            // Send the (possibly edited) quantity through as a string.
            quantity_requested: r.qty,
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
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.unit') }}</th>
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
                                <td class="px-5 py-4 text-sm text-slate-700">{{ unitShort(ing.unit) }}</td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-950">{{ ing.default_unit_cost }} <span class="text-[10px] text-slate-400">OMR</span></td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-500">{{ ing.min_stock_threshold ?? '—' }}</td>
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
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                        @click="openRestock(null, ingredients[0])"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.restock') }}
                    </button>
                    <!-- Phase A — piece-aware purchase batch. -->
                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100"
                        @click="openPurchase(null, ingredients[0])"
                    >
                        <ShoppingCart class="size-4" />
                        {{ t('inventory.actions.purchase') }}
                    </button>
                </div>

                <div v-if="branchStock.length === 0" class="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                    <Package class="mx-auto size-10 text-slate-300" />
                    <p class="mt-3 text-sm font-semibold text-slate-600">{{ t('inventory.empty_stock') }}</p>
                </div>
                <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.quantity') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.health') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.last_movement') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in branchStock" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.ingredient?.name ?? '—' }}</span>
                                    <span v-if="row.ingredient?.name_ar" class="block text-xs text-slate-500" dir="rtl">{{ row.ingredient.name_ar }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-sm font-semibold tabular-nums text-slate-950">
                                    {{ row.quantity }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ unitShort(row.ingredient?.unit ?? null) }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="healthBadgeClass(row.health_level)">
                                        {{ healthLabel(row.health_level) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDate(row.last_movement_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div v-if="canManage" class="inline-flex gap-2">
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50" @click="openAdjust(row)">
                                            <Minus class="size-3" /> {{ t('inventory.actions.adjust') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100" @click="openRestock(row)">
                                            <Plus class="size-3" /> {{ t('inventory.actions.restock') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100" @click="openPurchase(row)">
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
                            <option value="addon_consumption">{{ t('inventory.movement_types.addon_consumption') }}</option>
                            <option value="transfer_in">{{ t('inventory.movement_types.transfer_in') }}</option>
                            <option value="transfer_out">{{ t('inventory.movement_types.transfer_out') }}</option>
                            <option value="allocation_in">{{ t('inventory.movement_types.allocation_in') }}</option>
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
                                    {{ m.quantity }}
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ unitShort(m.ingredient?.unit ?? null) }}</span>
                                </td>
                                <td class="px-5 py-4 text-end text-xs tabular-nums text-slate-500">{{ m.unit_cost_at_time }} <span class="text-[10px] text-slate-400">OMR</span></td>
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
                                </td>
                                <td class="px-5 py-3 text-end text-sm font-semibold tabular-nums text-rose-700">
                                    -{{ w.quantity }} <span class="text-[10px] text-slate-500">{{ w.unit_at_set }}</span>
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
                                                            {{ line.counted_pieces }} {{ line.ingredient?.piece_unit_label ?? t('inventory.units.piece') }}
                                                            <span class="text-slate-400">(= {{ line.counted_units }} {{ line.ingredient?.unit ?? '' }})</span>
                                                        </template>
                                                        <template v-else>{{ line.counted_units }} {{ line.ingredient?.unit ?? '' }}</template>
                                                    </td>
                                                    <td class="px-2 py-1.5 text-end tabular-nums text-slate-600">{{ line.expected_units }}</td>
                                                    <td class="px-2 py-1.5 text-end font-semibold tabular-nums" :class="Number(line.variance_units) < 0 ? 'text-rose-600' : Number(line.variance_units) > 0 ? 'text-amber-600' : 'text-emerald-600'">
                                                        {{ line.variance_units }}
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
                                    <span v-for="(l, i) in tr.lines" :key="l.ingredient_id">{{ l.ingredient_name ?? ('#' + l.ingredient_id) }} ({{ l.quantity }}{{ l.unit ? ' ' + l.unit : '' }}){{ i < tr.lines.length - 1 ? ', ' : '' }}</span>
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
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.unit') }} *</span>
                            <select v-model="ingForm.unit" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="u in unitOptions" :key="u" :value="u">{{ unitLabel(u) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.default_unit_cost') }} (OMR)</span>
                            <input v-model="ingForm.default_unit_cost" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.min_stock_threshold') }}</span>
                            <input v-model="ingForm.min_stock_threshold" type="number" step="0.001" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.min_stock_threshold_hint') }}</p>
                        </label>
                    </div>
                    <!-- Phase A — piece unit (Additions §2.3). Label + ratio
                         are a pair: both set = piece-tracked (purchases and
                         day-end counts happen in pieces), both blank = not. -->
                    <fieldset class="rounded-lg border border-amber-200 bg-amber-50/40 p-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">
                            <Package class="me-1 inline size-3.5 text-amber-600" />
                            {{ t('inventory.piece.title') }}
                        </legend>
                        <p class="mb-2 text-xs text-slate-500">{{ t('inventory.piece.hint') }}</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('inventory.piece.label') }}</span>
                                <input v-model="ingForm.piece_unit_label" type="text" :placeholder="t('inventory.piece.label_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="ingModalErrors.piece_unit_label" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.piece_unit_label[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('inventory.piece.label_ar') }}</span>
                                <input v-model="ingForm.piece_unit_label_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('inventory.piece.units_per_piece', { unit: unitLabel(ingForm.unit) }) }}</span>
                                <input v-model="ingForm.units_per_piece" type="number" step="0.0001" min="0" inputmode="decimal" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="ingModalErrors.units_per_piece" class="mt-1 text-xs text-rose-600">{{ ingModalErrors.units_per_piece[0] }}</p>
                            </label>
                        </div>
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
                            {{ t('inventory.alt_units.title') }}
                        </legend>

                        <!-- New, unsaved ingredient: no uuid yet. -->
                        <div v-if="ingModalMode !== 'edit'" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                            {{ t('inventory.alt_units.save_first_hint') }}
                        </div>

                        <template v-else>
                            <p class="mb-2 text-xs text-slate-500">{{ t('inventory.alt_units.hint') }}</p>

                            <!-- Base unit reference (read-only). -->
                            <div class="mb-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600">
                                {{ t('inventory.alt_units.base_unit_label', { unit: unitLabel(ingForm.unit) }) }}
                            </div>

                            <!-- Section-level error banner (rose). -->
                            <div v-if="altUnitsError" class="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                                {{ altUnitsError }}
                            </div>

                            <div v-if="altUnitsLoading" class="text-xs text-slate-500">{{ t('common.loading') }}</div>

                            <template v-else>
                                <div v-if="altUnits.length === 0" class="rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                    {{ t('inventory.alt_units.empty') }}
                                </div>
                                <ul v-else class="space-y-2">
                                    <li
                                        v-for="unit in altUnits"
                                        :key="unit.uuid"
                                        class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2"
                                    >
                                        <label class="block flex-1 min-w-[8rem]">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.name') }}</span>
                                            <input
                                                :value="unit.name"
                                                type="text"
                                                readonly
                                                :title="t('inventory.alt_units.name_immutable_hint')"
                                                class="mt-1 w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-sm text-slate-600"
                                            >
                                        </label>
                                        <label v-if="altUnitDrafts[unit.uuid]" class="block w-36">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.name_ar') }}</span>
                                            <input
                                                v-model="altUnitDrafts[unit.uuid].name_ar"
                                                type="text"
                                                dir="rtl"
                                                :disabled="!canManage"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50"
                                            >
                                        </label>
                                        <label v-if="altUnitDrafts[unit.uuid]" class="block w-28">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.factor') }}</span>
                                            <input
                                                v-model="altUnitDrafts[unit.uuid].factor"
                                                type="number"
                                                step="0.0001"
                                                min="0"
                                                inputmode="decimal"
                                                :disabled="!canManage"
                                                class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50"
                                            >
                                            <p v-if="altUnitFieldErrors[unit.uuid] && altUnitFieldErrors[unit.uuid].factor" class="mt-1 text-[11px] text-rose-600">{{ altUnitFieldErrors[unit.uuid].factor[0] }}</p>
                                        </label>
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
                                                :title="t('inventory.alt_units.delete')"
                                                @click="removeAltUnit(unit)"
                                            >
                                                <Trash2 class="size-4" />
                                            </button>
                                        </div>
                                    </li>
                                </ul>

                                <!-- Add-new row — manage-gated. -->
                                <div v-if="canManage" class="mt-3 flex flex-wrap items-end gap-2 rounded border border-teal-100 bg-teal-50/40 p-2">
                                    <label class="block flex-1 min-w-[8rem]">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.name') }} *</span>
                                        <input
                                            v-model="altUnitNew.name"
                                            type="text"
                                            :placeholder="t('inventory.alt_units.name_placeholder')"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                        <p v-if="altUnitFieldErrors[''] && altUnitFieldErrors[''].name" class="mt-1 text-[11px] text-rose-600">{{ altUnitFieldErrors[''].name[0] }}</p>
                                    </label>
                                    <label class="block w-36">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.name_ar') }}</span>
                                        <input
                                            v-model="altUnitNew.name_ar"
                                            type="text"
                                            dir="rtl"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                    </label>
                                    <label class="block w-28">
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.alt_units.factor') }} *</span>
                                        <input
                                            v-model="altUnitNew.factor"
                                            type="number"
                                            step="0.0001"
                                            min="0"
                                            inputmode="decimal"
                                            placeholder="0"
                                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        >
                                        <p v-if="altUnitFieldErrors[''] && altUnitFieldErrors[''].factor" class="mt-1 text-[11px] text-rose-600">{{ altUnitFieldErrors[''].factor[0] }}</p>
                                    </label>
                                    <button
                                        type="button"
                                        :disabled="altUnitNewBusy || !altUnitNew.name.trim() || String(altUnitNew.factor).trim() === ''"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="addAltUnit"
                                    >
                                        <Plus class="size-3.5" />
                                        {{ altUnitNewBusy ? t('inventory.alt_units.saving') : t('inventory.alt_units.add') }}
                                    </button>
                                </div>
                                <p class="mt-2 text-[11px] text-slate-500">{{ t('inventory.alt_units.factor_hint') }}</p>
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
                            <input v-model="adjustForm.signed_quantity" required type="number" step="0.001" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <select v-model="adjustForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="">{{ unitShort(adjustTarget.ingredient.unit) }}</option>
                                <option v-for="au in (adjustTarget.ingredient.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
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
                                <input v-model="restockForm.quantity" required type="number" step="0.001" min="0.001" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="restockForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option value="">{{ unitShort(restockTarget.ingredient?.unit ?? null) }}</option>
                                    <option v-for="au in (restockTarget.ingredient?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
                                </select>
                            </div>
                            <p v-if="restockErrors.quantity" class="mt-1 text-xs text-rose-600">{{ restockErrors.quantity[0] }}</p>
                            <p v-if="restockErrors.unit" class="mt-1 text-xs text-rose-600">{{ restockErrors.unit[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.unit_cost_override') }}</span>
                            <input v-model="restockForm.unit_cost" type="number" step="0.001" min="0" :placeholder="restockTarget.ingredient?.default_unit_cost ?? '—'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
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
                        <input v-model="purchaseForm.pieces" type="number" :step="purchaseTarget.ingredient?.allow_fractional_pieces === false ? '1' : '0.001'" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="purchaseErrors.pieces" class="mt-1 text-xs text-rose-600">{{ purchaseErrors.pieces[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.purchase_modal.units', { unit: unitShort(purchaseTarget.ingredient?.unit ?? null) }) }}</span>
                        <input v-model="purchaseForm.units" type="number" step="0.001" min="0" placeholder="—" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
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
                <div v-else class="max-h-96 overflow-y-auto rounded-lg border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th class="px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.table.name') }}</th>
                                <th class="px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.modal.on_book') }}</th>
                                <th class="px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.counts.modal.counted') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(r, i) in countRows" :key="r.row.id">
                                <td class="px-4 py-2.5">
                                    <span class="block font-medium text-slate-800">{{ r.ingredient ? (isArabic && r.ingredient.name_ar ? r.ingredient.name_ar : r.ingredient.name) : '—' }}</span>
                                    <span v-if="pieceLabelFor(r.ingredient) !== null" class="block text-[11px] text-amber-700">
                                        {{ t('inventory.counts.modal.count_in', { label: pieceLabelFor(r.ingredient) ?? '' }) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-end tabular-nums text-slate-600">
                                    {{ r.row.quantity }} <span class="text-[10px] text-slate-400">{{ unitShort(r.ingredient?.unit ?? null) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-end">
                                    <input
                                        v-model="countRows[i].counted"
                                        type="number"
                                        :step="r.ingredient && pieceLabelFor(r.ingredient) !== null && r.ingredient.allow_fractional_pieces === false ? '1' : '0.001'"
                                        min="0"
                                        :placeholder="t('inventory.counts.modal.skip_placeholder')"
                                        class="w-32 rounded-lg border border-slate-200 px-2.5 py-1.5 text-end text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                    >
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
                        </select>
                        <p v-if="wasteErrors.ingredient_uuid" class="mt-1 text-xs text-rose-600">{{ wasteErrors.ingredient_uuid[0] }}</p>
                    </label>
                    <div v-if="wasteForm.ingredient_uuid" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        {{ t('inventory.waste.modal.current_balance') }}:
                        <span class="font-semibold text-slate-900">{{ wasteCurrentBalance }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.waste.modal.quantity') }} *</span>
                            <div class="mt-1 flex gap-2">
                                <input v-model="wasteForm.quantity" type="number" step="0.001" min="0.001" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <select v-model="wasteForm.unit" :title="t('inventory.fields.unit')" class="shrink-0 rounded-lg border border-slate-200 px-2 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option value="">{{ unitShort(wasteIngredient?.unit ?? null) }}</option>
                                    <option v-for="au in (wasteIngredient?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
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
                    <div v-if="wasteInsufficient" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                        <AlertTriangle class="me-1 inline size-3.5" />
                        {{ t('inventory.waste.modal.insufficient_warning') }}
                    </div>
                </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="wasteOpen = false">
                        {{ t('inventory.waste.modal.cancel') }}
                    </button>
                    <button type="submit" form="waste-modal-form" :disabled="wasteBusy || wasteInsufficient" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60">
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
                                <input v-model="line.quantity" type="number" step="0.001" min="0.001" :placeholder="t('inventory.restock.create_modal.quantity')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm tabular-nums">
                                <select v-model="line.unit" :title="t('inventory.fields.unit')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                    <option value="">{{ unitShort(ingredientByUuid(line.ingredient_uuid)?.unit ?? null) }}</option>
                                    <option v-for="au in (ingredientByUuid(line.ingredient_uuid)?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
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
                                <input v-model="line.quantity" type="number" step="0.001" min="0.001" :placeholder="t('inventory.transfers.create_modal.quantity')" class="sm:col-span-3 rounded-lg border border-slate-200 px-2 py-2 text-sm tabular-nums">
                                <select v-model="line.unit" :title="t('inventory.fields.unit')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                    <option value="">{{ unitShort(ingredientByUuid(line.ingredient_uuid)?.unit ?? null) }}</option>
                                    <option v-for="au in (ingredientByUuid(line.ingredient_uuid)?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
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
                                    <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ l.quantity_requested }} <span class="text-[10px] text-slate-500">{{ l.unit_at_set }}</span></td>
                                    <td class="px-3 py-2 text-end tabular-nums font-semibold text-emerald-700">{{ l.quantity_allocated }}</td>
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
                                <th class="px-3 py-2 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.restock.allocate_modal.allocated') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="l in allocateTarget.lines" :key="l.id">
                                <td class="px-3 py-2 font-medium text-slate-900">
                                    {{ l.ingredient ? (isArabic && l.ingredient.name_ar ? l.ingredient.name_ar : l.ingredient.name) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ l.quantity_requested }} <span class="text-[10px] text-slate-500">{{ l.unit_at_set }}</span></td>
                                <td class="px-3 py-2 text-end">
                                    <input v-model="allocateOverrides[String(l.id)]" type="number" step="0.001" min="0" :max="l.quantity_requested" class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-sm tabular-nums text-end">
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
                                    <span class="ms-1 text-[10px] font-normal text-slate-400">{{ row.suggestion.unit }}</span>
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ row.suggestion.current_quantity }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ row.suggestion.avg_daily_consumption }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-slate-700">{{ row.suggestion.target_level }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider" :class="reasonBadgeClass(row.suggestion.reason)">
                                        {{ reasonLabel(row.suggestion.reason) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-end">
                                    <input v-model="row.qty" :disabled="!row.include" type="number" step="0.001" min="0" class="w-28 rounded-lg border border-slate-200 px-2 py-1.5 text-sm tabular-nums text-end focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-400">
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

        <!-- P-G4 — central ingredient warehouse dialog -->
        <IngredientStockDialog
            :open="warehouseDialogIngredient !== null"
            :ingredient-uuid="warehouseDialogIngredient?.uuid ?? null"
            :ingredient-name="warehouseDialogIngredient?.name ?? ''"
            :can-manage="canManage"
            @close="warehouseDialogIngredient = null"
        />
    </MerchantLayout>
</template>

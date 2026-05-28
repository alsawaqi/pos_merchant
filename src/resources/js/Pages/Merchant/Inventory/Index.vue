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
    Boxes,
    Building2,
    Check,
    CheckCircle2,
    ClipboardList,
    History,
    Image as ImageIcon,
    Minus,
    Package,
    Pencil,
    Plus,
    Send,
    Trash,
    Trash2,
    Truck,
    Users,
    X,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    adjustStock,
    allocateRestockRequest,
    approveRestockRequest,
    cancelRestockRequest,
    createIngredient,
    createRestockRequest,
    createSupplier,
    deleteIngredient,
    deleteSupplier,
    listBranchStock,
    listIngredients,
    listRestockRequests,
    listStockMovements,
    listSuppliers,
    listWaste,
    recordWaste,
    rejectRestockRequest,
    restockStock,
    submitRestockRequest,
    updateIngredient,
    updateRestockRequest,
    updateSupplier,
    type BranchStockRow,
    type Ingredient,
    type IngredientUnit,
    type InventoryStatus,
    type PaginatedMovements,
    type PaginatedWaste,
    type RestockLinePayload,
    type RestockRequest,
    type RestockRequestStatus,
    type StockMovementType,
    type Supplier,
    type WasteReason,
    type WasteRecord,
} from '@/lib/api/inventory';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const isArabic = computed(() => locale.value === 'ar');
const canManage = computed(() => can(MerchantPermission.InventoryManage));
// Phase 5c — split restock permissions: create on the requester
// side, review on the HQ side. Either one gives the user access
// to the Restock Requests tab (it's READ-also for InventoryView).
const canCreateRestock = computed(() => can(MerchantPermission.RestockRequestCreate));
const canReviewRestock = computed(() => can(MerchantPermission.RestockRequestReview));

type TabKey = 'ingredients' | 'suppliers' | 'stock' | 'movements' | 'waste' | 'restock_requests';
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

const loading = ref(true);
const error = ref<string | null>(null);

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
    default_unit_cost: string;
    min_stock_threshold: string;
    primary_supplier_id: number | null;
    status: InventoryStatus;
}>({
    name: '',
    name_ar: '',
    unit: 'kg',
    default_unit_cost: '0.000',
    min_stock_threshold: '',
    primary_supplier_id: null,
    status: 'active',
});

const unitOptions: IngredientUnit[] = ['kg', 'g', 'l', 'ml', 'piece', 'pack', 'box'];

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
const adjustForm = reactive<{ signed_quantity: string; note: string }>({
    signed_quantity: '',
    note: '',
});

const restockOpen = ref(false);
const restockBusy = ref(false);
const restockError = ref<string | null>(null);
const restockErrors = ref<Record<string, string[]>>({});
const restockTarget = ref<{ ingredient: Ingredient | null; row: BranchStockRow | null }>({
    ingredient: null,
    row: null,
});
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
}>({ ingredient_uuid: '', quantity: '', reason: 'spoiled', notes: '', occurred_at: '' });

const restockModalOpen = ref(false);
const restockModalBusy = ref(false);
const restockModalError = ref<string | null>(null);
const restockModalErrors = ref<Record<string, string[]>>({});
const restockModalMode = ref<'create' | 'edit'>('create');
const restockModalTarget = ref<RestockRequest | null>(null);
const restockForm2 = reactive<{
    branch_uuid: string;
    note: string;
    lines: { ingredient_uuid: string; quantity: string; note: string }[];
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

// =================== Adjust/Restock modal form (Phase 5a) ========
// (Existing block kept below — only renamed-by-context, not by
// shape, to disambiguate from Phase 5c restockModalOpen above.)

const restockForm = reactive<{
    quantity: string;
    unit_cost: string;
    supplier_uuid: string;
    note: string;
}>({ quantity: '', unit_cost: '', supplier_uuid: '', note: '' });

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

async function bootstrap(): Promise<void> {
    loading.value = true;
    error.value = null;
    // Restock requests aren't branch-scoped on the API — load
    // them eagerly so the count badge on the tab is accurate
    // even before the user clicks the tab.
    await Promise.all([fetchBranches(), fetchIngredients(), fetchSuppliers(), fetchRestockRequests()]);
    if (selectedBranchUuid.value !== null) {
        await Promise.all([fetchBranchStock(), fetchMovements(), fetchWaste()]);
    }
    loading.value = false;
}

onMounted(() => {
    void bootstrap();
});

// Re-fetch stock + movements + waste when the branch picker changes.
watch(selectedBranchUuid, () => {
    void fetchBranchStock();
    void fetchMovements();
    void fetchWaste();
});

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

// =================== Ingredient flows ============================

function openCreateIngredient(): void {
    ingModalMode.value = 'create';
    ingModalTarget.value = null;
    ingForm.name = '';
    ingForm.name_ar = '';
    ingForm.unit = 'kg';
    ingForm.default_unit_cost = '0.000';
    ingForm.min_stock_threshold = '';
    ingForm.primary_supplier_id = null;
    ingForm.status = 'active';
    ingModalErrors.value = {};
    ingModalError.value = null;
    ingModalOpen.value = true;
}

function openEditIngredient(ingredient: Ingredient): void {
    ingModalMode.value = 'edit';
    ingModalTarget.value = ingredient;
    ingForm.name = ingredient.name;
    ingForm.name_ar = ingredient.name_ar ?? '';
    ingForm.unit = ingredient.unit;
    ingForm.default_unit_cost = ingredient.default_unit_cost;
    ingForm.min_stock_threshold = ingredient.min_stock_threshold ?? '';
    ingForm.primary_supplier_id = ingredient.primary_supplier_id;
    ingForm.status = ingredient.status;
    ingModalErrors.value = {};
    ingModalError.value = null;
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
            default_unit_cost: ingForm.default_unit_cost,
            min_stock_threshold: ingForm.min_stock_threshold.trim() === ''
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
    restockErrors.value = {};
    restockError.value = null;
    restockOpen.value = true;
}

async function submitRestock(): Promise<void> {
    if (selectedBranchUuid.value === null || restockTarget.value.ingredient === null) return;
    restockBusy.value = true;
    restockErrors.value = {};
    restockError.value = null;
    try {
        await restockStock(selectedBranchUuid.value, {
            ingredient_uuid: restockTarget.value.ingredient.uuid,
            quantity: restockForm.quantity,
            unit_cost: restockForm.unit_cost.trim() === '' ? null : restockForm.unit_cost,
            supplier_uuid: restockForm.supplier_uuid || null,
            note: restockForm.note.trim() || null,
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
    const qty = parseFloat(wasteForm.quantity || '0');
    if (!Number.isFinite(balance) || !Number.isFinite(qty)) return false;
    return qty > 0 && qty > balance;
});

const wasteCostPreview = computed<string>(() => {
    if (!wasteForm.ingredient_uuid) return '0.000';
    const ing = ingredients.value.find((i) => i.uuid === wasteForm.ingredient_uuid);
    if (!ing) return '0.000';
    const cost = parseFloat(ing.default_unit_cost) * parseFloat(wasteForm.quantity || '0');
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
    wasteErrors.value = {};
    wasteError.value = null;
    wasteOpen.value = true;
}

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
    restockForm2.lines = [{ ingredient_uuid: '', quantity: '', note: '' }];
    restockModalErrors.value = {};
    restockModalError.value = null;
    restockModalOpen.value = true;
}

function openEditRestock(req: RestockRequest): void {
    restockModalMode.value = 'edit';
    restockModalTarget.value = req;
    restockForm2.branch_uuid = req.branch?.uuid ?? '';
    restockForm2.note = req.note ?? '';
    restockForm2.lines = (req.lines ?? []).map((l) => ({
        ingredient_uuid: l.ingredient?.uuid ?? '',
        quantity: l.quantity_requested,
        note: l.note ?? '',
    }));
    if (restockForm2.lines.length === 0) {
        restockForm2.lines = [{ ingredient_uuid: '', quantity: '', note: '' }];
    }
    restockModalErrors.value = {};
    restockModalError.value = null;
    restockModalOpen.value = true;
}

function addRestockLine(): void {
    restockForm2.lines.push({ ingredient_uuid: '', quantity: '', note: '' });
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
            <div class="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
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
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
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
            <section v-if="activeTab === 'stock' || activeTab === 'movements'" class="space-y-4">
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
                <div v-if="canManage && ingredients.length > 0" class="flex justify-end">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-700 transition hover:bg-teal-100"
                        @click="openRestock(null, ingredients[0])"
                    >
                        <Plus class="size-4" />
                        {{ t('inventory.actions.restock') }}
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
        </section>

        <!-- ================== INGREDIENT MODAL ================== -->
        <div v-if="ingModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ ingModalMode === 'create' ? t('inventory.ing_modal.create_title') : t('inventory.ing_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitIngredient">
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
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="ingModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ ingModalBusy ? t('inventory.ing_modal.submitting') : t('inventory.ing_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== SUPPLIER MODAL ================== -->
        <div v-if="supModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ supModalMode === 'create' ? t('inventory.sup_modal.create_title') : t('inventory.sup_modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitSupplier">
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
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supModalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="supModalBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ supModalBusy ? t('inventory.sup_modal.submitting') : t('inventory.sup_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== ADJUST MODAL ================== -->
        <div v-if="adjustOpen && adjustTarget.ingredient" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.adjust_modal.title', { ingredient: adjustTarget.ingredient.name }) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ t('inventory.adjust_modal.subtitle') }}</p>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitAdjust">
                    <div v-if="adjustError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ adjustError }}
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">
                            {{ t('inventory.fields.signed_quantity') }} ({{ unitShort(adjustTarget.ingredient.unit) }}) *
                        </span>
                        <input v-model="adjustForm.signed_quantity" required type="number" step="0.001" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.signed_quantity_hint') }}</p>
                        <p v-if="adjustErrors.signed_quantity" class="mt-1 text-xs text-rose-600">{{ adjustErrors.signed_quantity[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.note_required') }} *</span>
                        <textarea v-model="adjustForm.note" required rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        <p class="mt-1 text-xs text-slate-500">{{ t('inventory.fields.note_required_hint') }}</p>
                        <p v-if="adjustErrors.note" class="mt-1 text-xs text-rose-600">{{ adjustErrors.note[0] }}</p>
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="adjustOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="adjustBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ adjustBusy ? t('inventory.adjust_modal.submitting') : t('inventory.adjust_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== RESTOCK MODAL ================== -->
        <div v-if="restockOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock_modal.title', { ingredient: restockTarget.ingredient?.name ?? '' }) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ t('inventory.restock_modal.subtitle') }}</p>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitRestock">
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
                            <span class="text-sm font-medium text-slate-700">{{ t('inventory.fields.quantity') }} ({{ unitShort(restockTarget.ingredient?.unit ?? null) }}) *</span>
                            <input v-model="restockForm.quantity" required type="number" step="0.001" min="0.001" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="restockErrors.quantity" class="mt-1 text-xs text-rose-600">{{ restockErrors.quantity[0] }}</p>
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
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="restockOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="restockBusy" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ restockBusy ? t('inventory.restock_modal.submitting') : t('inventory.restock_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== DELETE CONFIRMS ================== -->
        <div v-if="ingDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.delete_ing_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('inventory.delete_ing_dialog.body', { name: ingDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="ingDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteIngredient">
                        {{ deleting ? t('inventory.delete_ing_dialog.submitting') : t('inventory.delete_ing_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="supDeleteTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.delete_sup_dialog.title') }}</h2>
                </div>
                <div class="px-6 py-5 text-sm text-slate-700">{{ t('inventory.delete_sup_dialog.body', { name: supDeleteTarget.name }) }}</div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="supDeleteTarget = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleting" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="confirmDeleteSupplier">
                        {{ deleting ? t('inventory.delete_sup_dialog.submitting') : t('inventory.delete_sup_dialog.confirm') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ================== PHASE 5c — RECORD WASTE MODAL ================== -->
        <div v-if="wasteOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ t('inventory.waste.modal.title', { branch: selectedBranchName }) }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitRecordWaste">
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
                            <input v-model="wasteForm.quantity" type="number" step="0.001" min="0.001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="wasteErrors.quantity" class="mt-1 text-xs text-rose-600">{{ wasteErrors.quantity[0] }}</p>
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
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="wasteOpen = false">
                            {{ t('inventory.waste.modal.cancel') }}
                        </button>
                        <button type="submit" :disabled="wasteBusy || wasteInsufficient" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ wasteBusy ? t('inventory.waste.modal.submitting') : t('inventory.waste.modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== PHASE 5c — CREATE/EDIT RESTOCK REQUEST MODAL ================== -->
        <div v-if="restockModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ restockModalMode === 'create' ? t('inventory.restock.create_modal.title_create') : t('inventory.restock.create_modal.title_edit') }}
                    </h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submitRestockModal">
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
                                <select v-model="line.ingredient_uuid" class="sm:col-span-5 rounded-lg border border-slate-200 px-2 py-2 text-sm">
                                    <option value="">{{ t('inventory.restock.create_modal.ingredient_placeholder') }}</option>
                                    <option v-for="i in ingredients" :key="i.uuid" :value="i.uuid">{{ isArabic && i.name_ar ? i.name_ar : i.name }} ({{ i.unit }})</option>
                                </select>
                                <input v-model="line.quantity" type="number" step="0.001" min="0.001" :placeholder="t('inventory.restock.create_modal.quantity')" class="sm:col-span-2 rounded-lg border border-slate-200 px-2 py-2 text-sm tabular-nums">
                                <input v-model="line.note" type="text" :placeholder="t('inventory.restock.create_modal.line_note')" class="sm:col-span-4 rounded-lg border border-slate-200 px-2 py-2 text-sm">
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

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="restockModalOpen = false">
                            {{ t('inventory.restock.create_modal.cancel') }}
                        </button>
                        <button type="submit" :disabled="restockModalBusy || restockHasDuplicates" class="rounded-lg bg-gradient-to-r from-indigo-600 to-cyan-600 px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ restockModalBusy ? t('inventory.restock.create_modal.submitting') : (restockModalMode === 'create' ? t('inventory.restock.create_modal.submit_create') : t('inventory.restock.create_modal.submit_edit')) }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== PHASE 5c — SHOW RESTOCK REQUEST MODAL ================== -->
        <div v-if="showOpen && showTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.show_modal.title', { branch: showTarget.branch?.name ?? '—' }) }}</h2>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="restockStatusBadgeClass(showTarget.status)">
                            {{ t(`inventory.restock.statuses.${showTarget.status}`) }}
                        </span>
                    </div>
                </div>
                <div class="space-y-4 p-6">
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
                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="showOpen = false">{{ t('inventory.restock.show_modal.close') }}</button>
                </div>
            </div>
        </div>

        <!-- ================== PHASE 5c — REVIEW (APPROVE/REJECT) MODAL ================== -->
        <div v-if="reviewOpen && reviewTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ reviewMode === 'approve' ? t('inventory.restock.review_modal.title_approve') : t('inventory.restock.review_modal.title_reject') }}
                    </h2>
                </div>
                <form class="space-y-3 p-6" @submit.prevent="submitReview">
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
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="reviewOpen = false">{{ t('inventory.restock.review_modal.cancel') }}</button>
                        <button type="submit" :disabled="reviewBusy" :class="reviewMode === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'" class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-wait disabled:opacity-60">
                            {{ reviewBusy ? t('inventory.restock.review_modal.submitting') : (reviewMode === 'approve' ? t('inventory.restock.review_modal.submit_approve') : t('inventory.restock.review_modal.submit_reject')) }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== PHASE 5c — CANCEL MODAL ================== -->
        <div v-if="cancelOpen && cancelTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.cancel_modal.title') }}</h2>
                </div>
                <form class="space-y-3 p-6" @submit.prevent="submitCancel">
                    <div v-if="cancelError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ cancelError }}
                    </div>
                    <p class="text-xs text-slate-600">{{ t('inventory.restock.cancel_modal.hint') }}</p>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('inventory.restock.cancel_modal.note') }}</span>
                        <textarea v-model="cancelNote" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></textarea>
                    </label>
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="cancelOpen = false">{{ t('inventory.restock.cancel_modal.back') }}</button>
                        <button type="submit" :disabled="cancelBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60">
                            {{ cancelBusy ? t('inventory.restock.cancel_modal.submitting') : t('inventory.restock.cancel_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================== PHASE 5c — ALLOCATE MODAL ================== -->
        <div v-if="allocateOpen && allocateTarget" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('inventory.restock.allocate_modal.title') }}</h2>
                    <p class="mt-1 text-xs text-slate-600">{{ t('inventory.restock.allocate_modal.hint') }}</p>
                </div>
                <form class="space-y-3 p-6" @submit.prevent="submitAllocate">
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
                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="allocateOpen = false">{{ t('inventory.restock.allocate_modal.cancel') }}</button>
                        <button type="submit" :disabled="allocateBusy || allocateHasOver" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60">
                            {{ allocateBusy ? t('inventory.restock.allocate_modal.submitting') : t('inventory.restock.allocate_modal.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </MerchantLayout>
</template>

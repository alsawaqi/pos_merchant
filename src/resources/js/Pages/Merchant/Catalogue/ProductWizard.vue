<script setup lang="ts">
/**
 * PD1 — the 3-step product wizard PAGE, replacing the old cramped
 * product modal for BOTH create and edit (multi-vendor reality: not
 * everyone has a screen that fits a 2xl modal with ten fieldsets).
 *
 *   Step 1 — Basics: the stock-tracking TYPE first (it decides what
 *            the rest of the form shows), then names, category,
 *            description, image, SKU/barcode, all pricing incl. the
 *            per-provider grid, low-stock threshold (piece-counted
 *            types), shelf life (cooked), internal flag (ready),
 *            and the daily availability window.
 *   Step 2 — Add-ons & composition: shared add-on groups, add-ons
 *            UNIQUE to this product (creatable right here, even
 *            before the product exists), the recipe (made-to-order
 *            + cooked ONLY), physical items, branch availability.
 *   Step 3 — Review: read-only summary of everything → confirm.
 *
 * CREATE submits ONE atomic POST /api/products/wizard (all-or-nothing
 * server transaction; owned groups ride along — no more "save first").
 * EDIT keeps the per-section idempotent endpoints: the product already
 * exists, so a partial failure is retry-safe, and owned-group changes
 * apply immediately like the old modal did.
 */

import {
    ArrowLeft, Beaker, Boxes, Building2, Check, Image, Minus, Package,
    Plus, Sparkles, Tag, Trash2, Truck,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter, RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createAddOn,
    createProductAddOnGroup,
    createProductWizard,
    deleteAddOn,
    deleteAddOnGroup,
    getProduct,
    getProductAddOnGroups,
    listAddOnGroups,
    listAddonLinkOptions,
    listCategories,
    listComponentOptions,
    listProducts,
    syncProductAddOnGroups,
    syncProductBranches,
    updateProduct,
    updateProductComponents,
    updateProductRecipe,
    type AddOnGroup,
    type AddOnSelectionMode,
    type AddonLinkOption,
    type Category,
    type ComponentLinePayload,
    type ComponentOption,
    type CreateProductPayload,
    type Product,
    type ProductBranchAssignment,
    type ProductStatus,
    type RecipeLinePayload,
    type WizardOwnedGroupPayload,
    type WizardOwnedOptionPayload,
} from '@/lib/api/catalogue';
import { listIngredients, type Ingredient } from '@/lib/api/inventory';
import { listBranches, type Branch as BranchLite } from '@/lib/api/branches';
import {
    listDeliveryProviders,
    listProductDeliveryPrices,
    removeProductDeliveryPrice,
    setProductDeliveryPrice,
    type DeliveryProvider,
} from '@/lib/api/deliveryProviders';
import { authState } from '@/stores/auth';
import { MerchantPermission } from '@/lib/permissions';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const { can } = usePermissions();

const canManage = computed(() => can(MerchantPermission.CatalogueManage));

// Edit mode when the route carries a uuid (/catalogue/products/:uuid/edit).
const editUuid = route.name === 'merchant.catalogue.product-edit' ? String(route.params.uuid) : null;
const isEdit = editUuid !== null;

// F5 — branch assignment is HQ-only (full-replace would wipe other
// branches' rows); scoped users simply don't get the section and the
// wizard submits branches: null (available everywhere).
const isUnrestricted = computed(() => (authState.user?.branch_scope ?? null) === null);

// ---- Steps --------------------------------------------------------
const step = ref(1);
const maxVisitedStep = ref(1);
const STEPS = [1, 2, 3] as const;

function stepTitle(n: number): string {
    return t(`catalogue.wizard.steps.${n === 1 ? 'basics' : n === 2 ? 'composition' : 'review'}`);
}

// ---- Reference data ----------------------------------------------
const categories = ref<Category[]>([]);
const addOnGroups = ref<AddOnGroup[]>([]);
const ingredients = ref<Ingredient[]>([]);
const componentOptions = ref<ComponentOption[]>([]);
const addonLinkOptions = ref<AddonLinkOption[]>([]);
const deliveryProviders = ref<DeliveryProvider[]>([]);
const branches = ref<BranchLite[]>([]);

const selectableAddOnGroups = computed(() => addOnGroups.value.filter((g) => !g.is_global && g.owner_product_id === null));
const activeProviders = computed(() => deliveryProviders.value.filter((p) => p.is_active));

// ---- Page state ----------------------------------------------------
const pageLoading = ref(true);
const pageError = ref<string | null>(null);
const submitting = ref(false);
const submitError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});
const editTarget = ref<Product | null>(null);

// ---- The form (same field set as the old modal) --------------------
const form = reactive<{
    name: string;
    name_ar: string;
    description: string;
    image_url: string;
    category_id: number | null;
    sku: string;
    barcode: string;
    base_price: string;
    delivery_price: string;
    cost_price: string;
    tax_rate: string;
    tax_inclusive: boolean;
    show_on_customer_tablet: boolean;
    available_from: string;
    available_until: string;
    display_order: number;
    status: ProductStatus;
    stock_mode: string;
    low_stock_threshold: string;
    shelf_life_days: string;
    is_internal: boolean;
    component_rows: { component_uuid: string; quantity: string }[];
    addon_group_uuids: string[];
    recipe_lines: { ingredient_uuid: string; quantity: string; unit: string }[];
    branch_all: boolean;
    branch_rows: { branch_id: number; selected: boolean; stock_qty: string | number }[];
}>({
    name: '',
    name_ar: '',
    description: '',
    image_url: '',
    category_id: null,
    sku: '',
    barcode: '',
    base_price: '',
    delivery_price: '',
    cost_price: '',
    tax_rate: '',
    tax_inclusive: false,
    show_on_customer_tablet: true,
    available_from: '',
    available_until: '',
    display_order: 0,
    status: 'active',
    stock_mode: 'untracked',
    low_stock_threshold: '',
    shelf_life_days: '',
    is_internal: false,
    component_rows: [],
    addon_group_uuids: [],
    recipe_lines: [],
    branch_all: true,
    branch_rows: [],
});

// The recipe belongs only to types whose ingredients get consumed.
const hasRecipeStep = computed(() => form.stock_mode === 'ingredient' || form.stock_mode === 'cooked');
const isPieceCounted = computed(() => form.stock_mode === 'unit' || form.stock_mode === 'cooked');

// ---- Provider price grid (step 1) ----------------------------------
const providerPrices = ref<Record<string, string>>({});
const providerPricesTouched = ref<Record<string, boolean>>({});

function markProviderPriceTouched(providerUuid: string): void {
    providerPricesTouched.value[providerUuid] = true;
}

// ---- Owned (product-unique) add-on groups ---------------------------
// CREATE: drafts buffered locally, submitted inside the atomic wizard
// call. EDIT: persisted immediately via the existing endpoints, exactly
// like the old modal (the product id already exists).
interface DraftOption {
    name: string;
    name_ar: string;
    price_delta: string;
    is_default: boolean;
    linked_product_uuid: string;
}
interface DraftGroup {
    /** Stable client-side key — NEVER the array index (deleting a
     * draft would shift sibling option forms onto the wrong group). */
    key: string;
    name: string;
    name_ar: string;
    selection_mode: AddOnSelectionMode;
    min_selections: string;
    max_selections: string;
    options: DraftOption[];
}
const ownedDrafts = ref<DraftGroup[]>([]);
let draftSeq = 0;
const ownedAddonGroups = ref<AddOnGroup[]>([]); // edit mode, persisted
const ownedBusy = ref(false);
const ownedError = ref<string | null>(null);

const ownedGroupForm = reactive<{
    name: string;
    name_ar: string;
    selection_mode: AddOnSelectionMode;
    min_selections: string;
    max_selections: string;
}>({ name: '', name_ar: '', selection_mode: 'single', min_selections: '', max_selections: '' });

// One inline option form per group (draft index or persisted uuid).
const optionForms = ref<Record<string, DraftOption>>({});

function blankOption(): DraftOption {
    return { name: '', name_ar: '', price_delta: '0', is_default: false, linked_product_uuid: '' };
}

function optionFormFor(key: string): DraftOption {
    if (!optionForms.value[key]) optionForms.value[key] = blankOption();
    return optionForms.value[key];
}

// Picking a linked product prefills the option name (still editable).
function onOptionProductPicked(key: string): void {
    const formRow = optionFormFor(key);
    const pick = addonLinkOptions.value.find((p) => p.uuid === formRow.linked_product_uuid);
    if (pick && formRow.name.trim() === '') {
        formRow.name = pick.name;
        formRow.name_ar = pick.name_ar ?? '';
    }
}

function resetOwnedGroupForm(): void {
    ownedGroupForm.name = '';
    ownedGroupForm.name_ar = '';
    ownedGroupForm.selection_mode = 'single';
    ownedGroupForm.min_selections = '';
    ownedGroupForm.max_selections = '';
}

async function addOwnedGroup(): Promise<void> {
    if (ownedGroupForm.name.trim() === '') return;
    // Min/max ride type="number" inputs — Vue auto-casts a typed value
    // to a NUMBER despite the string declaration; coerce before trim.
    const minRaw = String(ownedGroupForm.min_selections ?? '').trim();
    const maxRaw = String(ownedGroupForm.max_selections ?? '').trim();
    if (!isEdit) {
        ownedDrafts.value.push({
            key: `draft-${++draftSeq}`,
            name: ownedGroupForm.name.trim(),
            name_ar: ownedGroupForm.name_ar.trim(),
            selection_mode: ownedGroupForm.selection_mode,
            min_selections: minRaw,
            max_selections: maxRaw,
            options: [],
        });
        resetOwnedGroupForm();
        return;
    }

    ownedBusy.value = true;
    ownedError.value = null;
    try {
        await createProductAddOnGroup(editUuid!, {
            name: ownedGroupForm.name.trim(),
            name_ar: ownedGroupForm.name_ar.trim() || null,
            selection_mode: ownedGroupForm.selection_mode,
            min_selections: minRaw === '' ? null : Number(minRaw),
            max_selections: maxRaw === '' ? null : Number(maxRaw),
        });
        resetOwnedGroupForm();
        await loadOwnedAddonGroups();
    } catch (err) {
        ownedError.value = apiMessage(err, t('catalogue.product_addons.save_failed'));
    } finally {
        ownedBusy.value = false;
    }
}

async function addOwnedOption(key: string, persistedGroupUuid: string | null, draftIndex: number | null): Promise<void> {
    const formRow = optionFormFor(key);
    if (formRow.name.trim() === '') return;

    const optionPayload: WizardOwnedOptionPayload = {
        name: formRow.name.trim(),
        name_ar: formRow.name_ar.trim() || null,
        price_delta: String(formRow.price_delta ?? '').trim() === '' ? '0' : String(formRow.price_delta).trim(),
        is_default: formRow.is_default,
        linked_product_uuid: formRow.linked_product_uuid || null,
    };

    if (draftIndex !== null) {
        ownedDrafts.value[draftIndex]?.options.push({
            name: optionPayload.name,
            name_ar: formRow.name_ar.trim(),
            price_delta: String(optionPayload.price_delta),
            is_default: formRow.is_default,
            linked_product_uuid: formRow.linked_product_uuid,
        });
        optionForms.value[key] = blankOption();
        return;
    }

    if (persistedGroupUuid === null) return;
    ownedBusy.value = true;
    ownedError.value = null;
    try {
        await createAddOn(persistedGroupUuid, optionPayload);
        optionForms.value[key] = blankOption();
        await loadOwnedAddonGroups();
    } catch (err) {
        ownedError.value = apiMessage(err, t('catalogue.product_addons.save_failed'));
    } finally {
        ownedBusy.value = false;
    }
}

function removeDraftOption(groupIndex: number, optionIndex: number): void {
    ownedDrafts.value[groupIndex]?.options.splice(optionIndex, 1);
}

function removeDraftGroup(groupIndex: number): void {
    const draft = ownedDrafts.value[groupIndex];
    if (draft) delete optionForms.value[draft.key];
    ownedDrafts.value.splice(groupIndex, 1);
}

/** The optionForms key for a card: persisted uuid (edit) / stable draft key. */
function groupFormKey(group: AddOnGroup | DraftGroup): string {
    return isEdit ? (group as AddOnGroup).uuid : (group as DraftGroup).key;
}

async function removePersistedOption(addonUuid: string): Promise<void> {
    ownedBusy.value = true;
    ownedError.value = null;
    try {
        await deleteAddOn(addonUuid);
        await loadOwnedAddonGroups();
    } catch (err) {
        ownedError.value = apiMessage(err, t('catalogue.product_addons.save_failed'));
    } finally {
        ownedBusy.value = false;
    }
}

async function removePersistedGroup(groupUuid: string): Promise<void> {
    ownedBusy.value = true;
    ownedError.value = null;
    try {
        await deleteAddOnGroup(groupUuid);
        await loadOwnedAddonGroups();
    } catch (err) {
        ownedError.value = apiMessage(err, t('catalogue.product_addons.save_failed'));
    } finally {
        ownedBusy.value = false;
    }
}

async function loadOwnedAddonGroups(): Promise<void> {
    if (!isEdit) return;
    const response = await getProductAddOnGroups(editUuid!);
    ownedAddonGroups.value = response.data;
}

// ---- Recipe helpers (ported from the modal) -------------------------
function addRecipeLine(): void {
    form.recipe_lines.push({ ingredient_uuid: '', quantity: '', unit: '' });
}

function removeRecipeLine(idx: number): void {
    form.recipe_lines.splice(idx, 1);
}

function ingredientByUuid(uuid: string): Ingredient | null {
    if (!uuid) return null;
    return ingredients.value.find((i) => i.uuid === uuid) ?? null;
}

function ingredientUnitLabel(uuid: string): string {
    return ingredientByUuid(uuid)?.unit ?? '';
}

function wireUnit(selected: string): string | null {
    return selected.trim() === '' ? null : selected;
}

function toBaseUnits(qty: number, ingredient: Ingredient | null | undefined, selected: string): number {
    if (!ingredient || selected.trim() === '') return qty;
    const alt = (ingredient.alt_units ?? []).find((u) => u.name === selected);
    if (!alt) return qty;
    const factor = parseFloat(alt.factor);
    if (!Number.isFinite(factor)) return qty;
    return qty * factor;
}

const recipeLiveCost = computed<string>(() => {
    let total = 0;
    for (const line of form.recipe_lines) {
        if (!line.ingredient_uuid || line.quantity === '') continue;
        const ingredient = ingredients.value.find((i) => i.uuid === line.ingredient_uuid);
        if (!ingredient) continue;
        const qty = toBaseUnits(parseFloat(line.quantity), ingredient, line.unit);
        const cost = parseFloat(ingredient.default_unit_cost);
        if (!isFinite(qty) || !isFinite(cost)) continue;
        total += qty * cost;
    }
    return total.toFixed(3);
});

const recipeLiveMargin = computed<string | null>(() => {
    const basePrice = parseFloat(form.base_price);
    const cost = parseFloat(recipeLiveCost.value);
    if (!isFinite(basePrice) || basePrice <= 0) return null;
    if (!isFinite(cost)) return null;
    return (((basePrice - cost) / basePrice) * 100).toFixed(1);
});

const recipeHasDuplicates = computed<boolean>(() => {
    const seen = new Set<string>();
    for (const line of form.recipe_lines) {
        if (!line.ingredient_uuid) continue;
        if (seen.has(line.ingredient_uuid)) return true;
        seen.add(line.ingredient_uuid);
    }
    return false;
});

// ---- Branch helpers --------------------------------------------------
function branchName(branchId: number): string {
    return branches.value.find((b) => b.id === branchId)?.name ?? `#${branchId}`;
}

function buildBranchRows(assignments?: ProductBranchAssignment[]): { branch_id: number; selected: boolean; stock_qty: string | number }[] {
    const byBranch = new Map<number, ProductBranchAssignment>();
    for (const a of assignments ?? []) byBranch.set(a.branch_id, a);
    return branches.value.map((b) => {
        const a = byBranch.get(b.id);
        return {
            branch_id: b.id,
            selected: a ? a.is_available : false,
            stock_qty: a && a.stock_qty !== null ? String(a.stock_qty) : '',
        };
    });
}

// ---- Misc helpers ----------------------------------------------------
function apiMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        const message = (err.payload as { message?: unknown }).message;
        if (typeof message === 'string' && message !== '') return message;
    }
    return err instanceof Error && err.message !== '' ? err.message : fallback;
}

// 422 errors arrive keyed 'product.name' (wizard create) or 'name'
// (edit PATCH) — read both so the same template works in both modes.
// Nested rows (owned_groups.0.name, delivery_prices.2.price, …) fall
// back to a prefix match so their message surfaces on the section
// banner instead of being swallowed.
function fieldError(key: string): string | null {
    const errors = fieldErrors.value;
    const exact = errors[key] ?? errors[`product.${key}`];
    if (exact && exact.length > 0) return exact[0]!;
    const prefix = `${key}.`;
    for (const [k, messages] of Object.entries(errors)) {
        if (k.startsWith(prefix) && messages.length > 0) return messages[0]!;
    }
    return null;
}

/**
 * Edit mode talks to the per-section endpoints whose payloads use
 * different field names (recipe/components both PUT { lines }, the
 * group sync PUTs { group_uuids }) — re-key their 422s onto the
 * wizard's section names so the banners + step routing work.
 */
function remapSectionErrors(err: unknown, from: string, to: string): never {
    if (err instanceof ApiError && err.isValidationError()) {
        const source = err.payload.errors as Record<string, string[]>;
        const renamed: Record<string, string[]> = {};
        for (const [k, v] of Object.entries(source)) {
            renamed[k === from || k.startsWith(`${from}.`) ? to + k.slice(from.length) : k] = v;
        }
        (err.payload as { errors: Record<string, string[]> }).errors = renamed;
    }
    throw err;
}

function categoryName(id: number | null): string {
    if (id === null) return t('catalogue.uncategorized');
    return categories.value.find((c) => c.id === id)?.name ?? '—';
}

function selectionModeLabel(mode: AddOnSelectionMode | null): string {
    if (!mode) return '—';
    return t(`catalogue.selection_modes.${mode}`);
}

// ---- Step navigation ------------------------------------------------
const stepOneErrors = ref<string[]>([]);

function validateStepOne(): boolean {
    const missing: string[] = [];
    if (form.name.trim() === '') missing.push(t('catalogue.wizard.name_required'));
    if (form.base_price === '' || Number(form.base_price) < 0 || !isFinite(Number(form.base_price))) {
        missing.push(t('catalogue.wizard.price_required'));
    }
    stepOneErrors.value = missing;
    return missing.length === 0;
}

function goNext(): void {
    if (step.value === 1 && !validateStepOne()) return;
    if (step.value < 3) {
        step.value += 1;
        maxVisitedStep.value = Math.max(maxVisitedStep.value, step.value);
        window.scrollTo({ top: 0 });
    }
}

function goBack(): void {
    if (step.value > 1) {
        step.value -= 1;
        window.scrollTo({ top: 0 });
    }
}

function goToStep(n: number): void {
    if (n === step.value) return;
    if (n > maxVisitedStep.value) return; // forward jumps go through Next (validated)
    if (n > 1 && !validateStepOne()) {
        step.value = 1;
        return;
    }
    step.value = n;
    window.scrollTo({ top: 0 });
}

// ---- Payload builders ------------------------------------------------
function productPayload(): CreateProductPayload {
    return {
        name: form.name.trim(),
        name_ar: form.name_ar.trim() || null,
        description: form.description.trim() || null,
        image_url: form.image_url.trim() || null,
        category_id: form.category_id ?? null,
        sku: form.sku.trim() || null,
        barcode: form.barcode.trim() || null,
        base_price: form.base_price,
        delivery_price: form.delivery_price === '' ? null : form.delivery_price,
        cost_price: form.cost_price === '' ? null : form.cost_price,
        tax_rate: form.tax_rate === '' ? null : form.tax_rate,
        tax_inclusive: form.tax_inclusive,
        show_on_customer_tablet: form.show_on_customer_tablet,
        available_from: form.available_from ? `${form.available_from}:00` : null,
        available_until: form.available_until ? `${form.available_until}:00` : null,
        stock_mode: form.stock_mode as 'unit' | 'ingredient' | 'untracked' | 'cooked',
        low_stock_threshold: isPieceCounted.value && form.low_stock_threshold !== '' ? form.low_stock_threshold : null,
        shelf_life_days: form.stock_mode === 'cooked' && form.shelf_life_days !== '' ? Number(form.shelf_life_days) : null,
        is_internal: form.stock_mode === 'unit' ? form.is_internal : false,
        display_order: form.display_order,
    };
}

function recipePayload(): RecipeLinePayload[] {
    // A type without ingredient consumption carries no recipe — on edit
    // an emptied list also CLEARS any recipe left from a previous type.
    if (!hasRecipeStep.value) return [];
    return form.recipe_lines
        .filter((l) => l.ingredient_uuid && l.quantity !== '')
        .map((l) => ({ ingredient_uuid: l.ingredient_uuid, quantity: l.quantity, unit: wireUnit(l.unit) }));
}

function componentsPayload(): ComponentLinePayload[] {
    if (form.stock_mode === 'unit' && form.is_internal) return [];
    return form.component_rows
        .filter((l) => l.component_uuid && l.quantity !== '')
        .map((l) => ({ component_uuid: l.component_uuid, quantity: l.quantity }));
}

function branchesPayload(): ProductBranchAssignment[] | null {
    if (!isUnrestricted.value) return null;
    if (form.branch_all) return isEdit ? [] : null; // create: nothing to clear
    return form.branch_rows
        .filter((r) => r.selected)
        .map((r) => ({
            branch_id: r.branch_id,
            is_available: true,
            // Units belong to ready/bought-in products ONLY — for the
            // recipe-driven types the server ignores stock_qty anyway
            // (made-to-order follows ingredient stock; cooked shelf
            // counts are written by kitchen production).
            stock_qty: form.stock_mode === 'unit' && String(r.stock_qty ?? '').trim() !== ''
                ? Number(r.stock_qty)
                : null,
        }));
}

function ownedGroupsPayload(): WizardOwnedGroupPayload[] {
    return ownedDrafts.value.map((g) => ({
        name: g.name,
        name_ar: g.name_ar || null,
        selection_mode: g.selection_mode,
        min_selections: String(g.min_selections ?? '').trim() === '' ? null : Number(g.min_selections),
        max_selections: String(g.max_selections ?? '').trim() === '' ? null : Number(g.max_selections),
        options: g.options.map((o) => ({
            name: o.name,
            name_ar: o.name_ar || null,
            price_delta: o.price_delta === '' ? '0' : o.price_delta,
            is_default: o.is_default,
            linked_product_uuid: o.linked_product_uuid || null,
        })),
    }));
}

function deliveryPricesPayload(): { provider_uuid: string; price: string }[] {
    const rows: { provider_uuid: string; price: string }[] = [];
    for (const provider of activeProviders.value) {
        const value = (providerPrices.value[provider.uuid] ?? '').trim();
        if (value !== '') rows.push({ provider_uuid: provider.uuid, price: value });
    }
    return rows;
}

// ---- Submit -----------------------------------------------------------
async function submit(): Promise<void> {
    submitting.value = true;
    submitError.value = null;
    fieldErrors.value = {};
    try {
        if (!isEdit) {
            await createProductWizard({
                product: productPayload(),
                addon_group_uuids: form.addon_group_uuids,
                owned_groups: ownedGroupsPayload(),
                recipe_lines: recipePayload(),
                component_lines: componentsPayload(),
                branches: branchesPayload(),
                delivery_prices: deliveryPricesPayload(),
            });
        } else {
            const uuid = editUuid!;
            await updateProduct(uuid, { ...productPayload(), status: form.status });
            await syncProductAddOnGroups(uuid, form.addon_group_uuids)
                .catch((e) => remapSectionErrors(e, 'group_uuids', 'addon_group_uuids'));
            await updateProductRecipe(uuid, { lines: recipePayload() })
                .catch((e) => remapSectionErrors(e, 'lines', 'recipe_lines'));
            await updateProductComponents(uuid, componentsPayload())
                .catch((e) => remapSectionErrors(e, 'lines', 'component_lines'));
            const branchSync = branchesPayload();
            if (branchSync !== null) {
                await syncProductBranches(uuid, branchSync);
            }
            // Per-provider price overrides — touched rows only.
            for (const provider of activeProviders.value) {
                if (!providerPricesTouched.value[provider.uuid]) continue;
                const value = (providerPrices.value[provider.uuid] ?? '').trim();
                if (value === '') {
                    await removeProductDeliveryPrice(uuid, provider.uuid);
                } else {
                    await setProductDeliveryPrice(uuid, provider.uuid, { price: value });
                }
            }
        }

        leavingAfterSave.value = true;
        void router.push({ path: '/catalogue', query: { tab: 'products' } });
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            submitError.value = t('catalogue.validation_summary');
            // Land the user on the step that owns the first error.
            const keys = Object.keys(err.payload.errors);
            const stepTwoKey = (k: string): boolean =>
                k.startsWith('recipe_lines') || k.startsWith('component_lines')
                || k.startsWith('owned_groups') || k.startsWith('addon_group_uuids')
                || k.startsWith('branches');
            step.value = keys.some((k) => !stepTwoKey(k)) ? 1 : 2;
        } else {
            submitError.value = apiMessage(err, t('catalogue.wizard.save_failed'));
        }
        window.scrollTo({ top: 0 });
    } finally {
        submitting.value = false;
    }
}

// ---- Load -------------------------------------------------------------
async function loadReferenceData(): Promise<void> {
    // Soft-fail the optional pickers exactly like the old modal did.
    await Promise.all([
        listCategories().then((r) => { categories.value = r.data; }).catch(() => { categories.value = []; }),
        listAddOnGroups().then((r) => { addOnGroups.value = r.data; }).catch(() => { addOnGroups.value = []; }),
        listIngredients().then((r) => { ingredients.value = r.data; }).catch(() => { ingredients.value = []; }),
        listComponentOptions().then((r) => { componentOptions.value = r.data; }).catch(() => { componentOptions.value = []; }),
        listAddonLinkOptions().then((r) => { addonLinkOptions.value = r.data; }).catch(() => { addonLinkOptions.value = []; }),
        listDeliveryProviders().then((r) => { deliveryProviders.value = r.data; }).catch(() => { deliveryProviders.value = []; }),
        listBranches().then((r) => { branches.value = r.data; }).catch(() => { branches.value = []; }),
    ]);
}

function prefillFromProduct(product: Product): void {
    editTarget.value = product;
    form.name = product.name;
    form.name_ar = product.name_ar ?? '';
    form.description = product.description ?? '';
    form.image_url = product.image_url ?? '';
    form.category_id = product.category_id;
    form.sku = product.sku ?? '';
    form.barcode = product.barcode ?? '';
    form.base_price = product.base_price;
    form.delivery_price = product.delivery_price ?? '';
    form.cost_price = product.cost_price ?? '';
    form.tax_rate = product.tax_rate ?? '';
    form.tax_inclusive = product.tax_inclusive ?? false;
    form.show_on_customer_tablet = product.show_on_customer_tablet ?? true;
    form.available_from = product.available_from?.slice(0, 5) ?? '';
    form.available_until = product.available_until?.slice(0, 5) ?? '';
    form.display_order = product.display_order;
    form.status = (product.status ?? 'active') as ProductStatus;
    form.stock_mode = product.stock_mode ?? 'untracked';
    form.low_stock_threshold = product.low_stock_threshold ?? '';
    form.shelf_life_days = product.shelf_life_days !== null && product.shelf_life_days !== undefined
        ? String(product.shelf_life_days)
        : '';
    form.is_internal = product.is_internal ?? false;
    form.component_rows = (product.component_lines ?? []).map((line) => ({
        component_uuid: line.component_uuid,
        quantity: line.quantity,
    }));
    // Shared groups only — owned groups render in their own editor.
    form.addon_group_uuids = (product.addon_groups ?? [])
        .filter((g) => g.owner_product_id === null && !g.is_global)
        .map((g) => g.uuid);
    form.recipe_lines = (product.recipe_lines ?? []).map((line) => ({
        ingredient_uuid: line.ingredient?.uuid ?? '',
        quantity: line.quantity,
        unit: '',
    }));
    form.branch_all = (product.branches ?? []).length === 0;
    form.branch_rows = buildBranchRows(product.branches);
}

onMounted(async () => {
    pageLoading.value = true;
    pageError.value = null;
    try {
        await loadReferenceData();

        if (isEdit) {
            const [productRes, pricesRes] = await Promise.all([
                getProduct(editUuid!),
                listProductDeliveryPrices(editUuid!).catch(() => ({ data: [] })),
            ]);
            prefillFromProduct(productRes.data);
            for (const row of pricesRes.data) {
                const providerUuid = row.delivery_provider?.uuid;
                if (providerUuid) providerPrices.value[providerUuid] = row.price;
            }
            await loadOwnedAddonGroups().catch(() => { ownedAddonGroups.value = []; });
        } else {
            form.branch_rows = buildBranchRows();
            // New products sort to the end of the full catalogue.
            try {
                const meta = await listProducts({ per_page: 1 });
                form.display_order = meta.meta.total;
            } catch {
                form.display_order = 0;
            }
        }
    } catch (err) {
        pageError.value = err instanceof ApiError && err.status === 404
            ? t('catalogue.wizard.not_found')
            : apiMessage(err, t('catalogue.wizard.load_failed'));
    } finally {
        pageLoading.value = false;
        dirtyBaseline.value = dirtySnapshot();
        window.addEventListener('beforeunload', onBeforeUnload);
    }
});

// ---- Unsaved-changes guard ------------------------------------------------
// The page exposes many more exits than the old modal did (breadcrumb,
// sidebar, browser back) while buffering strictly more work (owned-group
// drafts). Snapshot the editable state after load; confirm before leaving
// when it changed and the wizard wasn't submitted.
const leavingAfterSave = ref(false);
const dirtyBaseline = ref('');

function dirtySnapshot(): string {
    return JSON.stringify({ form, drafts: ownedDrafts.value, prices: providerPrices.value });
}

function isDirty(): boolean {
    return !leavingAfterSave.value && dirtyBaseline.value !== '' && dirtySnapshot() !== dirtyBaseline.value;
}

onBeforeRouteLeave(() => {
    if (isDirty() && !window.confirm(t('catalogue.wizard.unsaved_confirm'))) {
        return false;
    }
    return true;
});

function onBeforeUnload(e: BeforeUnloadEvent): void {
    if (isDirty()) e.preventDefault();
}

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
});

// ---- Review helpers -----------------------------------------------------
const reviewRecipeLines = computed(() => form.recipe_lines.filter((l) => l.ingredient_uuid && l.quantity !== ''));
const reviewComponents = computed(() => form.component_rows.filter((l) => l.component_uuid && l.quantity !== ''));
const reviewSharedGroups = computed(() => addOnGroups.value.filter((g) => form.addon_group_uuids.includes(g.uuid)));
const reviewProviderPrices = computed(() => activeProviders.value
    .map((p) => ({ provider: p, price: (providerPrices.value[p.uuid] ?? '').trim() }))
    .filter((row) => row.price !== ''));
const reviewSelectedBranches = computed(() => form.branch_rows.filter((r) => r.selected));
const droppedRecipeLines = computed(() => !hasRecipeStep.value && form.recipe_lines.some((l) => l.ingredient_uuid));
// Internal items consume nothing themselves — entered component rows
// will be dropped at save, so the review must say so (not list them).
const componentsAreDropped = computed(() => form.stock_mode === 'unit' && form.is_internal && form.component_rows.some((l) => l.component_uuid !== ''));

function componentName(uuid: string): string {
    return componentOptions.value.find((o) => o.uuid === uuid)?.name ?? '—';
}

/** True for a persisted AddOn with linked_product OR a draft with the uuid set. */
function optionIsLinked(option: unknown): boolean {
    const row = option as { linked_product_uuid?: unknown; linked_product?: unknown };
    if (typeof row.linked_product_uuid === 'string' && row.linked_product_uuid !== '') return true;
    return row.linked_product !== null && row.linked_product !== undefined;
}

/** Template-safe accessor (object-literal casts don't parse in templates). */
function persistedUuid(row: unknown): string {
    return String((row as { uuid?: unknown }).uuid ?? '');
}

function ingredientName(uuid: string): string {
    return ingredientByUuid(uuid)?.name ?? '—';
}

const typeOptions = ['untracked', 'ingredient', 'cooked', 'unit'] as const;
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-4xl">
            <RouterLink
                :to="{ path: '/catalogue', query: { tab: 'products' } }"
                class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition hover:text-slate-900"
            >
                <ArrowLeft class="size-3.5" />
                {{ t('catalogue.wizard.back') }}
            </RouterLink>

            <h1 class="text-2xl font-bold text-slate-950">
                {{ isEdit ? t('catalogue.wizard.edit_title') : t('catalogue.wizard.create_title') }}
            </h1>
            <p v-if="isEdit && editTarget" class="mt-1 text-sm text-slate-500">{{ editTarget.name }}</p>

            <!-- Forbidden / loading / load-error -->
            <div v-if="!canManage" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                {{ t('catalogue.wizard.forbidden') }}
            </div>
            <div v-else-if="pageLoading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                {{ t('common.loading') }}
            </div>
            <div v-else-if="pageError" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-900">
                {{ pageError }}
            </div>

            <template v-else>
                <!-- Stepper -->
                <nav class="mt-6 flex items-center gap-2">
                    <template v-for="(n, i) in STEPS" :key="n">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold transition"
                            :class="step === n
                                ? 'bg-slate-950 text-white'
                                : n <= maxVisitedStep
                                    ? 'bg-teal-50 text-teal-800 hover:bg-teal-100'
                                    : 'bg-slate-100 text-slate-400'"
                            :disabled="n > maxVisitedStep"
                            @click="goToStep(n)"
                        >
                            <span
                                class="grid size-5 place-items-center rounded-full text-[11px] font-bold"
                                :class="step === n ? 'bg-white text-slate-950' : n < step ? 'bg-teal-600 text-white' : 'bg-white text-slate-500'"
                            >
                                <Check v-if="n < step" class="size-3" />
                                <template v-else>{{ n }}</template>
                            </span>
                            {{ stepTitle(n) }}
                        </button>
                        <div v-if="i < STEPS.length - 1" class="h-px w-6 bg-slate-200" />
                    </template>
                </nav>

                <!-- Submit / validation banner -->
                <div v-if="submitError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ submitError }}
                </div>
                <div v-if="stepOneErrors.length > 0 && step === 1" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    <p v-for="msg in stepOneErrors" :key="msg">{{ msg }}</p>
                </div>

                <form class="mt-5 space-y-5" @submit.prevent>
                    <!-- ============ STEP 1 — BASICS ============ -->
                    <template v-if="step === 1">
                        <!-- The TYPE comes first: it decides the rest of the form. -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.type_label') }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ t('catalogue.wizard.type_hint') }}</p>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="mode in typeOptions"
                                    :key="mode"
                                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                                    :class="form.stock_mode === mode ? 'border-teal-500 bg-teal-50/60 ring-2 ring-teal-100' : 'border-slate-200 hover:bg-slate-50'"
                                >
                                    <input v-model="form.stock_mode" type="radio" :value="mode" class="mt-1 border-slate-300 text-teal-600 focus:ring-teal-500">
                                    <span>
                                        <span class="block text-sm font-semibold text-slate-900">{{ t(`catalogue.wizard.types.${mode}.label`) }}</span>
                                        <span class="block text-xs text-slate-500">{{ t(`catalogue.wizard.types.${mode}.desc`) }}</span>
                                    </span>
                                </label>
                            </div>
                        </section>

                        <!-- Identity -->
                        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.identity_title') }}</h2>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name') }} *</span>
                                    <input v-model="form.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <p v-if="fieldError('name')" class="mt-1 text-xs text-rose-600">{{ fieldError('name') }}</p>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.name_ar') }}</span>
                                    <input v-model="form.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                </label>
                            </div>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.category') }}</span>
                                <select v-model="form.category_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option :value="null">{{ t('catalogue.uncategorized') }}</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                </select>
                                <p v-if="fieldError('category_id')" class="mt-1 text-xs text-rose-600">{{ fieldError('category_id') }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.description') }}</span>
                                <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">
                                    <Image class="me-1 inline size-3" />
                                    {{ t('catalogue.fields.image_url') }}
                                </span>
                                <input v-model="form.image_url" type="url" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="fieldError('image_url')" class="mt-1 text-xs text-rose-600">{{ fieldError('image_url') }}</p>
                            </label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.sku') }}</span>
                                    <input v-model="form.sku" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <p v-if="fieldError('sku')" class="mt-1 text-xs text-rose-600">{{ fieldError('sku') }}</p>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.barcode') }}</span>
                                    <input v-model="form.barcode" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <p v-if="fieldError('barcode')" class="mt-1 text-xs text-rose-600">{{ fieldError('barcode') }}</p>
                                </label>
                            </div>
                            <label v-if="isEdit" class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.status') }}</span>
                                <select v-model="form.status" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option value="active">{{ t('catalogue.statuses.active') }}</option>
                                    <option value="inactive">{{ t('catalogue.statuses.inactive') }}</option>
                                </select>
                            </label>
                        </section>

                        <!-- Pricing (incl. the per-provider grid — step 1 by design) -->
                        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.pricing_title') }}</h2>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">
                                        <Tag class="me-1 inline size-3" />
                                        {{ t('catalogue.fields.base_price') }} (OMR) *
                                    </span>
                                    <input v-model="form.base_price" required type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <p v-if="fieldError('base_price')" class="mt-1 text-xs text-rose-600">{{ fieldError('base_price') }}</p>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.cost_price') }} (OMR)</span>
                                    <input v-model="form.cost_price" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.tax_rate') }} (%)</span>
                                    <input v-model="form.tax_rate" type="number" step="0.01" min="0" max="100" :placeholder="t('catalogue.fields.tax_rate_placeholder')" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.tax_rate_hint') }}</p>
                                </label>
                            </div>
                            <label class="block max-w-xs">
                                <span class="text-sm font-medium text-slate-700">
                                    <Truck class="me-1 inline size-3" />
                                    {{ t('catalogue.fields.delivery_price') }} (OMR)
                                </span>
                                <input v-model="form.delivery_price" type="number" step="0.001" min="0" :placeholder="form.base_price || '—'" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.delivery_price_hint') }}</p>
                            </label>
                            <div>
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input v-model="form.tax_inclusive" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                    {{ t('catalogue.fields.tax_inclusive') }}
                                </label>
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.tax_inclusive_hint') }}</p>
                            </div>

                            <!-- Per-provider price overrides -->
                            <div v-if="activeProviders.length > 0" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                <p class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <Truck class="size-4 text-teal-700" />
                                    {{ t('delivery_providers.product_grid.title') }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">{{ t('delivery_providers.product_grid.hint') }}</p>
                                <p v-if="fieldError('delivery_prices')" class="mt-2 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ fieldError('delivery_prices') }}</p>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <div v-for="provider in activeProviders" :key="provider.id" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                        <span class="inline-flex min-w-[7rem] items-center gap-2 text-sm font-medium text-slate-700">
                                            <span v-if="provider.color" class="inline-block size-3 rounded-full border border-slate-200" :style="{ backgroundColor: provider.color }"></span>
                                            {{ provider.name }}
                                        </span>
                                        <input
                                            :value="providerPrices[provider.uuid] ?? ''"
                                            type="text"
                                            inputmode="decimal"
                                            :placeholder="form.delivery_price || form.base_price || '0.000'"
                                            class="flex-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-mono text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                            @input="(e) => { providerPrices[provider.uuid] = (e.target as HTMLInputElement).value; markProviderPriceTouched(provider.uuid); }"
                                        >
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Type-specific stock settings -->
                        <section v-if="isPieceCounted || form.stock_mode === 'cooked'" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.stock_title') }}</h2>
                            <label v-if="isPieceCounted" class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.low_stock_threshold') }}</span>
                                <input v-model="form.low_stock_threshold" type="number" min="0" step="1" :placeholder="t('catalogue.fields.low_stock_threshold_placeholder')" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.low_stock_threshold_hint') }}</p>
                            </label>
                            <label v-if="form.stock_mode === 'cooked'" class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('catalogue.wizard.shelf_life') }}</span>
                                <input v-model="form.shelf_life_days" type="number" min="1" max="365" step="1" :placeholder="t('catalogue.wizard.shelf_life_placeholder')" class="mt-1 w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.wizard.shelf_life_hint') }}</p>
                            </label>
                            <label v-if="form.stock_mode === 'unit'" class="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                                <input v-model="form.is_internal" type="checkbox" class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                <span>
                                    <span class="block text-sm font-medium text-slate-700">{{ t('catalogue.wizard.internal_label') }}</span>
                                    <span class="block text-xs text-slate-500">{{ t('catalogue.wizard.internal_hint') }}</span>
                                </span>
                            </label>
                        </section>

                        <!-- Visibility + available hours -->
                        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-slate-900">{{ t('catalogue.wizard.visibility_title') }}</h2>
                            <div>
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input v-model="form.show_on_customer_tablet" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                    {{ t('catalogue.fields.show_on_tablet') }}
                                </label>
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.show_on_tablet_hint') }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-700">{{ t('catalogue.fields.available_hours') }}</p>
                                <div class="mt-1 grid max-w-md grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_from') }}</label>
                                        <input v-model="form.available_from" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-600">{{ t('catalogue.fields.available_until') }}</label>
                                        <input v-model="form.available_until" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ t('catalogue.fields.available_hours_hint') }}</p>
                            </div>
                        </section>
                    </template>

                    <!-- ============ STEP 2 — ADD-ONS & COMPOSITION ============ -->
                    <template v-else-if="step === 2">
                        <!-- Shared add-on groups -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Sparkles class="size-4 text-teal-600" />
                                {{ t('catalogue.fields.addon_groups') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ t('catalogue.fields.addon_groups_hint') }}</p>
                            <div v-if="selectableAddOnGroups.length === 0" class="mt-3 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.empty_addon_groups') }}
                            </div>
                            <div v-else class="mt-3 grid gap-1.5 sm:grid-cols-2">
                                <label v-for="group in selectableAddOnGroups" :key="group.id" class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50">
                                    <input v-model="form.addon_group_uuids" type="checkbox" :value="group.uuid" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                    <span class="flex-1 truncate">{{ group.name }}</span>
                                    <span class="text-[10px] text-slate-400">{{ selectionModeLabel(group.selection_mode) }}</span>
                                </label>
                            </div>
                        </section>

                        <!-- Product-unique add-on groups (creatable RIGHT HERE in create mode) -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Sparkles class="size-4 text-indigo-600" />
                                {{ t('catalogue.product_addons.title') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ isEdit ? t('catalogue.wizard.owned_hint_edit') : t('catalogue.wizard.owned_hint') }}</p>

                            <div v-if="ownedError" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                                {{ ownedError }}
                            </div>
                            <p v-if="fieldError('owned_groups')" class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ fieldError('owned_groups') }}</p>

                            <!-- Existing groups: persisted (edit) or drafts (create) -->
                            <div class="mt-3 space-y-3">
                                <article
                                    v-for="(group, gi) in (isEdit ? ownedAddonGroups : ownedDrafts)"
                                    :key="groupFormKey(group)"
                                    class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                                >
                                    <header class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="truncate text-sm font-semibold text-slate-950">{{ group.name }}</h3>
                                            <p class="mt-0.5 text-[11px] text-slate-500">
                                                <Boxes class="me-1 inline size-3" />
                                                {{ selectionModeLabel(group.selection_mode as AddOnSelectionMode) }}
                                                <template v-if="group.min_selections || group.max_selections">
                                                    · {{ t('catalogue.wizard.min_max_badge', { min: group.min_selections ?? 0, max: group.max_selections ?? '∞' }) }}
                                                </template>
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            :disabled="ownedBusy"
                                            class="inline-flex items-center gap-1 rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="isEdit ? removePersistedGroup((group as AddOnGroup).uuid) : removeDraftGroup(gi)"
                                        >
                                            <Trash2 class="size-3" /> {{ t('catalogue.product_addons.delete_group') }}
                                        </button>
                                    </header>

                                    <!-- Options -->
                                    <ul v-if="(isEdit ? ((group as AddOnGroup).addons ?? []) : (group as DraftGroup).options).length > 0" class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-100 bg-slate-50/40">
                                        <li
                                            v-for="(option, oi) in (isEdit ? ((group as AddOnGroup).addons ?? []) : (group as DraftGroup).options)"
                                            :key="oi"
                                            class="flex items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">
                                                {{ option.name }}
                                                <span v-if="option.is_default" class="ms-1 rounded bg-teal-100 px-1.5 py-0.5 text-[10px] font-semibold text-teal-700">{{ t('catalogue.wizard.option_default') }}</span>
                                                <span v-if="optionIsLinked(option)" class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">{{ t('catalogue.wizard.option_linked') }}</span>
                                            </span>
                                            <span class="text-xs font-semibold tabular-nums text-slate-700">
                                                +{{ option.price_delta }}
                                                <span class="text-[10px] font-normal text-slate-400">OMR</span>
                                            </span>
                                            <button
                                                type="button"
                                                :disabled="ownedBusy"
                                                class="rounded p-1 text-rose-500 transition hover:bg-rose-100 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                :title="t('catalogue.product_addons.remove')"
                                                @click="isEdit ? removePersistedOption(persistedUuid(option)) : removeDraftOption(gi, oi)"
                                            >
                                                <Trash2 class="size-3.5" />
                                            </button>
                                        </li>
                                    </ul>

                                    <!-- Add option -->
                                    <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_7rem_auto_auto]">
                                        <label class="block">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.option_name') }}</span>
                                            <input v-model="optionFormFor(groupFormKey(group)).name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        </label>
                                        <label class="block">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.price_delta') }}</span>
                                            <input v-model="optionFormFor(groupFormKey(group)).price_delta" type="number" step="0.001" min="0" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        </label>
                                        <label class="block">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.wizard.option_linked') }}</span>
                                            <select
                                                v-model="optionFormFor(groupFormKey(group)).linked_product_uuid"
                                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                                @change="onOptionProductPicked(groupFormKey(group))"
                                            >
                                                <option value="">—</option>
                                                <option v-for="opt in addonLinkOptions" :key="opt.uuid" :value="opt.uuid">{{ opt.name }}</option>
                                            </select>
                                        </label>
                                        <div class="flex items-end gap-2">
                                            <label class="flex items-center gap-1.5 pb-1.5 text-[11px] font-semibold text-slate-600">
                                                <input v-model="optionFormFor(groupFormKey(group)).is_default" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                                {{ t('catalogue.wizard.option_default') }}
                                            </label>
                                            <button
                                                type="button"
                                                :disabled="ownedBusy || optionFormFor(groupFormKey(group)).name.trim() === ''"
                                                class="inline-flex items-center gap-1 rounded border border-teal-200 bg-teal-50 px-2.5 py-1.5 text-[11px] font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                @click="addOwnedOption(groupFormKey(group), isEdit ? (group as AddOnGroup).uuid : null, isEdit ? null : gi)"
                                            >
                                                <Plus class="size-3" /> {{ t('catalogue.product_addons.add_option') }}
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <!-- Add a group -->
                            <div class="mt-3 grid gap-2 rounded-lg border border-dashed border-slate-200 p-3 sm:grid-cols-[1fr_1fr_8rem_5rem_5rem_auto]">
                                <label class="block">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.group_name') }}</span>
                                    <input v-model="ownedGroupForm.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.fields.name_ar') }}</span>
                                    <input v-model="ownedGroupForm.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.wizard.selection_mode') }}</span>
                                    <select v-model="ownedGroupForm.selection_mode" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <option value="single">{{ t('catalogue.selection_modes.single') }}</option>
                                        <option value="multi">{{ t('catalogue.selection_modes.multi') }}</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.wizard.group_min') }}</span>
                                    <input v-model="ownedGroupForm.min_selections" type="number" min="0" max="99" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.wizard.group_max') }}</span>
                                    <input v-model="ownedGroupForm.max_selections" type="number" min="1" max="99" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                </label>
                                <div class="flex items-end">
                                    <button
                                        type="button"
                                        :disabled="ownedBusy || ownedGroupForm.name.trim() === ''"
                                        class="inline-flex items-center gap-1 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50"
                                        @click="addOwnedGroup"
                                    >
                                        <Plus class="size-3.5" /> {{ t('catalogue.product_addons.add_group') }}
                                    </button>
                                </div>
                            </div>
                        </section>

                        <!-- Recipe — made-to-order + cooked ONLY -->
                        <section v-if="hasRecipeStep" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Beaker class="size-4 text-amber-600" />
                                {{ t('catalogue.recipe.section_title') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ t('catalogue.recipe.section_hint') }}</p>
                            <p v-if="fieldError('recipe_lines')" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ fieldError('recipe_lines') }}</p>

                            <div v-if="ingredients.length === 0" class="mt-3 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.recipe.no_ingredients_hint') }}
                            </div>
                            <template v-else>
                                <div v-if="form.recipe_lines.length === 0" class="mt-3 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                    {{ t('catalogue.recipe.no_lines') }}
                                </div>
                                <ul v-else class="mt-3 space-y-2">
                                    <li v-for="(line, idx) in form.recipe_lines" :key="idx" class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-slate-50/50 p-2">
                                        <label class="min-w-xs block flex-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('inventory.fields.ingredient') }}</span>
                                            <select v-model="line.ingredient_uuid" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100" @change="line.unit = ''">
                                                <option value="">{{ t('catalogue.recipe.pick_ingredient') }}</option>
                                                <option v-for="ing in ingredients" :key="ing.id" :value="ing.uuid">{{ ing.name }} ({{ ing.unit }})</option>
                                            </select>
                                        </label>
                                        <label class="block w-28">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.quantity') }}</span>
                                            <input v-model="line.quantity" type="number" step="0.001" min="0.001" placeholder="0.000" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        </label>
                                        <label class="block w-24">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.recipe.unit') }}</span>
                                            <select v-model="line.unit" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                                <option value="">{{ ingredientUnitLabel(line.ingredient_uuid) }}</option>
                                                <option v-for="au in (ingredientByUuid(line.ingredient_uuid)?.alt_units ?? [])" :key="au.uuid" :value="au.name">{{ au.name }}</option>
                                            </select>
                                        </label>
                                        <button type="button" class="grid size-9 place-items-center rounded-lg border border-rose-200 text-rose-700 transition hover:bg-rose-50" :title="t('catalogue.recipe.remove_line')" @click="removeRecipeLine(idx)">
                                            <Minus class="size-4" />
                                        </button>
                                    </li>
                                </ul>
                                <button type="button" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100" @click="addRecipeLine">
                                    <Plus class="size-3.5" />
                                    {{ t('catalogue.recipe.add_line') }}
                                </button>
                                <p v-if="recipeHasDuplicates" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                    {{ t('catalogue.recipe.duplicate_ingredient') }}
                                </p>
                                <div v-if="form.recipe_lines.length > 0" class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">{{ t('catalogue.recipe.live_cost') }}</p>
                                        <p class="text-base font-semibold tabular-nums text-amber-900">{{ recipeLiveCost }} <span class="text-[10px] font-normal text-amber-600">OMR</span></p>
                                    </div>
                                    <div v-if="recipeLiveMargin !== null" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ t('catalogue.recipe.margin') }}</p>
                                        <p class="text-base font-semibold tabular-nums text-emerald-900">{{ recipeLiveMargin }}<span class="text-[10px] font-normal text-emerald-600">%</span></p>
                                    </div>
                                </div>
                            </template>
                        </section>

                        <!-- Physical items (hidden for internal items) -->
                        <section v-if="!(form.stock_mode === 'unit' && form.is_internal)" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Boxes class="size-4 text-sky-600" />
                                {{ t('catalogue.wizard.physical_title') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ t('catalogue.wizard.physical_hint') }}</p>
                            <p v-if="fieldError('component_lines')" class="mt-2 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{{ fieldError('component_lines') }}</p>

                            <div v-if="componentOptions.length === 0" class="mt-3 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.wizard.physical_empty') }}
                            </div>
                            <template v-else>
                                <div v-if="form.component_rows.length === 0" class="mt-3 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                    {{ t('catalogue.wizard.physical_none') }}
                                </div>
                                <ul v-else class="mt-3 space-y-2">
                                    <li v-for="(row, idx) in form.component_rows" :key="idx" class="flex items-center gap-2">
                                        <select v-model="row.component_uuid" class="w-full flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                            <option value="" disabled>{{ t('catalogue.wizard.physical_pick') }}</option>
                                            <option v-for="opt in componentOptions" :key="opt.uuid" :value="opt.uuid" :disabled="opt.uuid === editUuid">
                                                {{ opt.name }}{{ opt.is_internal ? ` (${t('catalogue.wizard.internal_badge')})` : '' }}
                                            </option>
                                        </select>
                                        <input v-model="row.quantity" type="number" min="0.001" step="1" :placeholder="t('catalogue.wizard.physical_qty')" class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                        <button type="button" class="grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 text-rose-600 transition hover:bg-rose-50" @click="form.component_rows.splice(idx, 1)">
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </li>
                                </ul>
                                <button type="button" class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100" @click="form.component_rows.push({ component_uuid: '', quantity: '1' })">
                                    <Plus class="size-3.5" />
                                    {{ t('catalogue.wizard.physical_add') }}
                                </button>
                            </template>
                        </section>

                        <!-- Branch availability (HQ users only — F5) -->
                        <section v-if="isUnrestricted" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Building2 class="size-4 text-teal-600" />
                                {{ t('catalogue.branches.section_title') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ t('catalogue.branches.section_hint') }}</p>
                            <p v-if="form.stock_mode !== 'unit'" class="mt-1.5 rounded border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
                                {{ form.stock_mode === 'cooked' ? t('catalogue.wizard.branches_cooked_hint') : t('catalogue.wizard.branches_recipe_hint') }}
                            </p>

                            <label class="mt-3 flex items-center gap-2 text-xs font-medium text-slate-700">
                                <input v-model="form.branch_all" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                {{ t('catalogue.branches.all_branches') }}
                            </label>

                            <div v-if="form.branch_all" class="mt-2 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.branches.all_branches_hint') }}
                            </div>
                            <div v-else-if="form.branch_rows.length === 0" class="mt-2 rounded border border-dashed border-slate-200 p-3 text-center text-xs italic text-slate-500">
                                {{ t('catalogue.branches.no_branches') }}
                            </div>
                            <ul v-else class="mt-2 space-y-1.5">
                                <li v-for="(row, idx) in form.branch_rows" :key="row.branch_id" class="flex items-center gap-2 rounded border border-slate-200 px-2.5 py-1.5 text-xs">
                                    <label class="flex flex-1 items-center gap-2 font-medium text-slate-700">
                                        <input v-model="form.branch_rows[idx]!.selected" type="checkbox" class="rounded border-slate-300 text-teal-600 focus:ring-2 focus:ring-teal-200">
                                        <span class="truncate">{{ branchName(row.branch_id) }}</span>
                                    </label>
                                    <input v-if="form.stock_mode === 'unit'" v-model="form.branch_rows[idx]!.stock_qty" type="number" min="0" step="1" :disabled="!row.selected" :placeholder="t('catalogue.branches.stock_placeholder')" class="w-24 rounded border border-slate-200 px-2 py-1 text-xs tabular-nums disabled:cursor-not-allowed disabled:bg-slate-50">
                                </li>
                            </ul>
                        </section>
                    </template>

                    <!-- ============ STEP 3 — REVIEW ============ -->
                    <template v-else>
                        <p class="text-sm text-slate-600">{{ isEdit ? t('catalogue.wizard.review_hint_edit') : t('catalogue.wizard.review_hint') }}</p>

                        <!-- Basics -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Package class="size-4 text-slate-500" />
                                {{ t('catalogue.wizard.steps.basics') }}
                            </h2>
                            <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.wizard.type_label') }}</dt><dd class="font-semibold text-slate-900">{{ t(`catalogue.wizard.types.${form.stock_mode}.label`) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.name') }}</dt><dd class="font-semibold text-slate-900">{{ form.name || '—' }}<span v-if="form.name_ar" class="ms-2 text-slate-500" dir="rtl">{{ form.name_ar }}</span></dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.category') }}</dt><dd class="font-semibold text-slate-900">{{ categoryName(form.category_id) }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.sku') }} / {{ t('catalogue.fields.barcode') }}</dt><dd class="font-mono text-slate-900">{{ form.sku || '—' }} / {{ form.barcode || '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.base_price') }}</dt><dd class="font-semibold tabular-nums text-slate-900">{{ form.base_price || '0.000' }} OMR</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.cost_price') }}</dt><dd class="tabular-nums text-slate-900">{{ form.cost_price || '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.delivery_price') }}</dt><dd class="tabular-nums text-slate-900">{{ form.delivery_price || t('catalogue.wizard.inherits_base') }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.tax_rate') }}</dt><dd class="tabular-nums text-slate-900">{{ form.tax_rate !== '' ? `${form.tax_rate}%` : t('catalogue.wizard.company_default') }}</dd></div>
                                <div><dt class="text-xs text-slate-500">{{ t('catalogue.fields.available_hours') }}</dt><dd class="tabular-nums text-slate-900">{{ form.available_from || form.available_until ? `${form.available_from || '00:00'} – ${form.available_until || '23:59'}` : t('catalogue.wizard.always_available') }}</dd></div>
                                <div v-if="isPieceCounted"><dt class="text-xs text-slate-500">{{ t('catalogue.fields.low_stock_threshold') }}</dt><dd class="tabular-nums text-slate-900">{{ form.low_stock_threshold || '—' }}</dd></div>
                                <div v-if="form.stock_mode === 'cooked'"><dt class="text-xs text-slate-500">{{ t('catalogue.wizard.shelf_life') }}</dt><dd class="tabular-nums text-slate-900">{{ form.shelf_life_days || t('catalogue.wizard.keeps') }}</dd></div>
                                <div v-if="form.stock_mode === 'unit' && form.is_internal"><dt class="text-xs text-slate-500">{{ t('catalogue.wizard.internal_label') }}</dt><dd class="font-semibold text-slate-900">✓</dd></div>
                                <div v-if="isEdit"><dt class="text-xs text-slate-500">{{ t('catalogue.fields.status') }}</dt><dd class="font-semibold text-slate-900">{{ t(`catalogue.statuses.${form.status}`) }}</dd></div>
                            </dl>
                            <div v-if="reviewProviderPrices.length > 0" class="mt-3 border-t border-slate-100 pt-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('delivery_providers.product_grid.title') }}</p>
                                <ul class="mt-1.5 flex flex-wrap gap-2">
                                    <li v-for="row in reviewProviderPrices" :key="row.provider.uuid" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                        {{ row.provider.name }}: <span class="font-semibold tabular-nums">{{ row.price }}</span> OMR
                                    </li>
                                </ul>
                            </div>
                        </section>

                        <!-- Add-ons -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Sparkles class="size-4 text-teal-600" />
                                {{ t('catalogue.wizard.review_addons') }}
                            </h2>
                            <div class="mt-3 space-y-2 text-sm">
                                <p v-if="reviewSharedGroups.length === 0 && (isEdit ? ownedAddonGroups.length === 0 : ownedDrafts.length === 0)" class="text-xs italic text-slate-500">
                                    {{ t('catalogue.wizard.review_none') }}
                                </p>
                                <div v-if="reviewSharedGroups.length > 0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.fields.addon_groups') }}</p>
                                    <ul class="mt-1.5 flex flex-wrap gap-2">
                                        <li v-for="g in reviewSharedGroups" :key="g.uuid" class="rounded-lg bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-800">{{ g.name }}</li>
                                    </ul>
                                </div>
                                <div v-if="(isEdit ? ownedAddonGroups.length : ownedDrafts.length) > 0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('catalogue.product_addons.title') }}</p>
                                    <ul class="mt-1.5 space-y-1">
                                        <li v-for="(g, gi) in (isEdit ? ownedAddonGroups : ownedDrafts)" :key="gi" class="text-xs text-slate-700">
                                            <span class="font-semibold">{{ g.name }}</span>
                                            — {{ t('catalogue.wizard.option_count', { count: (isEdit ? ((g as AddOnGroup).addons ?? []).length : (g as DraftGroup).options.length) }) }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </section>

                        <!-- Recipe -->
                        <section v-if="hasRecipeStep || droppedRecipeLines" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Beaker class="size-4 text-amber-600" />
                                {{ t('catalogue.recipe.section_title') }}
                            </h2>
                            <p v-if="droppedRecipeLines" class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
                                {{ t('catalogue.wizard.recipe_dropped') }}
                            </p>
                            <template v-else>
                                <p v-if="reviewRecipeLines.length === 0" class="mt-2 text-xs italic text-slate-500">{{ t('catalogue.wizard.review_none') }}</p>
                                <ul v-else class="mt-3 space-y-1 text-sm">
                                    <li v-for="(line, i) in reviewRecipeLines" :key="i" class="flex justify-between text-slate-700">
                                        <span>{{ ingredientName(line.ingredient_uuid) }}</span>
                                        <span class="tabular-nums">{{ line.quantity }} {{ line.unit || ingredientUnitLabel(line.ingredient_uuid) }}</span>
                                    </li>
                                </ul>
                                <div v-if="reviewRecipeLines.length > 0" class="mt-3 flex gap-4 border-t border-slate-100 pt-2 text-xs">
                                    <span class="text-amber-700">{{ t('catalogue.recipe.live_cost') }}: <strong class="tabular-nums">{{ recipeLiveCost }}</strong> OMR</span>
                                    <span v-if="recipeLiveMargin !== null" class="text-emerald-700">{{ t('catalogue.recipe.margin') }}: <strong class="tabular-nums">{{ recipeLiveMargin }}%</strong></span>
                                </div>
                            </template>
                        </section>

                        <!-- Physical items + branches -->
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h2 class="inline-flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Boxes class="size-4 text-sky-600" />
                                {{ t('catalogue.wizard.physical_title') }}
                            </h2>
                            <p v-if="componentsAreDropped" class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
                                {{ t('catalogue.wizard.components_dropped') }}
                            </p>
                            <template v-else>
                                <p v-if="reviewComponents.length === 0" class="mt-2 text-xs italic text-slate-500">{{ t('catalogue.wizard.review_none') }}</p>
                                <ul v-else class="mt-3 space-y-1 text-sm">
                                    <li v-for="(row, i) in reviewComponents" :key="i" class="flex justify-between text-slate-700">
                                        <span>{{ componentName(row.component_uuid) }}</span>
                                        <span class="tabular-nums">× {{ row.quantity }}</span>
                                    </li>
                                </ul>
                            </template>

                            <template v-if="isUnrestricted">
                                <h3 class="mt-4 inline-flex items-center gap-2 border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900">
                                    <Building2 class="size-4 text-teal-600" />
                                    {{ t('catalogue.branches.section_title') }}
                                </h3>
                                <p v-if="form.branch_all" class="mt-1 text-xs text-slate-600">{{ t('catalogue.branches.all_branches') }}</p>
                                <p v-else-if="reviewSelectedBranches.length === 0" class="mt-1 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
                                    {{ t('catalogue.wizard.branches_none_selected') }}
                                </p>
                                <ul v-else class="mt-1.5 flex flex-wrap gap-2">
                                    <li v-for="row in reviewSelectedBranches" :key="row.branch_id" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                        {{ branchName(row.branch_id) }}<span v-if="form.stock_mode === 'unit' && String(row.stock_qty).trim() !== ''" class="ms-1 tabular-nums text-slate-500">({{ row.stock_qty }})</span>
                                    </li>
                                </ul>
                            </template>
                        </section>
                    </template>

                    <!-- Footer navigation -->
                    <div class="flex items-center justify-between pb-8">
                        <button
                            v-if="step > 1"
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            @click="goBack"
                        >
                            {{ t('catalogue.wizard.back_step') }}
                        </button>
                        <span v-else />

                        <button
                            v-if="step < 3"
                            type="button"
                            :disabled="step === 2 && recipeHasDuplicates"
                            class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="goNext"
                        >
                            {{ t('catalogue.wizard.next') }}
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="submitting || recipeHasDuplicates"
                            class="rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="submit"
                        >
                            {{ submitting
                                ? t('catalogue.wizard.saving')
                                : isEdit ? t('catalogue.wizard.save') : t('catalogue.wizard.create') }}
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </MerchantLayout>
</template>

<script setup lang="ts">
/**
 * Discounts — Phase 6d.
 *
 * Per-merchant promo rules. The blueprint (§5.9) describes a
 * 7-axis configuration surface (scope, amount, validity,
 * day-of-week, time-of-day, branches, stackable + approval).
 *
 * The page lists rules with a "currently active" chip that
 * composes status + validity window (computed server-side
 * by DiscountResource); the create/edit modal walks through
 * the configuration axes.
 *
 * Permission gates:
 *   - Page reachable when DiscountsView
 *   - Add / Edit / Pause / Resume / Delete buttons only when
 *     DiscountsManage
 */

import { Calendar, CheckCircle2, Pause, PauseCircle, Pencil, Percent, Plus, PlayCircle, Tags, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createDiscount,
    deleteDiscount,
    listDiscounts,
    pauseDiscount,
    resumeDiscount,
    syncDiscountTargets,
    updateDiscount,
    type CreateDiscountPayload,
    type Discount,
    type DiscountAmountType,
    type DiscountScope,
    type DiscountTargetType,
} from '@/lib/api/discounts';
import { listCategories, listProducts, type Category, type Product } from '@/lib/api/catalogue';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

const discounts = ref<Discount[]>([]);
const products = ref<Product[]>([]);
const categories = ref<Category[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const canManage = computed(() => can(MerchantPermission.DiscountsManage));

async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const [d, p, c] = await Promise.all([listDiscounts(), listProducts(), listCategories()]);
        discounts.value = d.data;
        products.value = p.data;
        categories.value = c.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load discounts';
    } finally {
        loading.value = false;
    }
}

onMounted(() => { void fetchAll(); });

// ---- Modal -------------------------------------------------------
type ModalMode = 'create' | 'edit';
const modalOpen = ref(false);
const modalMode = ref<ModalMode>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalTarget = ref<Discount | null>(null);

interface FormShape {
    name: string;
    scope: DiscountScope;
    amount_type: DiscountAmountType;
    amount: string;
    validity_start: string;
    validity_end: string;
    dayofweek_mask: number;
    time_start: string;
    time_end: string;
    branch_scope_json: number[] | null;
    stackable: boolean;
    requires_manager_approval: boolean;
    target_product_ids: number[];
    target_category_ids: number[];
}

const form = reactive<FormShape>({
    name: '',
    scope: 'order',
    amount_type: 'percent',
    amount: '10',
    validity_start: '',
    validity_end: '',
    // Default = every day (127 = 0b1111111).
    dayofweek_mask: 127,
    time_start: '',
    time_end: '',
    branch_scope_json: null,
    stackable: false,
    requires_manager_approval: false,
    target_product_ids: [],
    target_category_ids: [],
});

// Day-of-week chip toggles. Sun=1..Sat=64.
const DOW_BITS: { key: string; bit: number }[] = [
    { key: 'sun', bit: 1 },
    { key: 'mon', bit: 2 },
    { key: 'tue', bit: 4 },
    { key: 'wed', bit: 8 },
    { key: 'thu', bit: 16 },
    { key: 'fri', bit: 32 },
    { key: 'sat', bit: 64 },
];

function isDowOn(bit: number): boolean {
    return (form.dayofweek_mask & bit) !== 0;
}

function toggleDow(bit: number): void {
    if (isDowOn(bit)) {
        form.dayofweek_mask &= ~bit;
    } else {
        form.dayofweek_mask |= bit;
    }
}

function resetForm(): void {
    form.name = '';
    form.scope = 'order';
    form.amount_type = 'percent';
    form.amount = '10';
    form.validity_start = '';
    form.validity_end = '';
    form.dayofweek_mask = 127;
    form.time_start = '';
    form.time_end = '';
    form.branch_scope_json = null;
    form.stackable = false;
    form.requires_manager_approval = false;
    form.target_product_ids = [];
    form.target_category_ids = [];
}

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    resetForm();
    modalError.value = null;
    modalOpen.value = true;
}

function openEdit(d: Discount): void {
    modalMode.value = 'edit';
    modalTarget.value = d;
    form.name = d.name;
    form.scope = d.scope;
    form.amount_type = d.amount_type;
    form.amount = d.amount;
    // Cast iso8601 → datetime-local input format (strip tz).
    form.validity_start = d.validity_start?.slice(0, 16) ?? '';
    form.validity_end = d.validity_end?.slice(0, 16) ?? '';
    form.dayofweek_mask = d.dayofweek_mask ?? 127;
    form.time_start = d.time_start?.slice(0, 5) ?? '';
    form.time_end = d.time_end?.slice(0, 5) ?? '';
    form.branch_scope_json = d.branch_scope_json;
    form.stackable = d.stackable;
    form.requires_manager_approval = d.requires_manager_approval;
    form.target_product_ids = (d.targets ?? [])
        .filter((tg) => tg.target_type === 'product')
        .map((tg) => tg.target_id);
    form.target_category_ids = (d.targets ?? [])
        .filter((tg) => tg.target_type === 'category')
        .map((tg) => tg.target_id);
    modalError.value = null;
    modalOpen.value = true;
}

async function submitModal(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    try {
        const payload: CreateDiscountPayload = {
            name: form.name,
            scope: form.scope,
            amount_type: form.amount_type,
            amount: form.amount,
            validity_start: form.validity_start || null,
            validity_end: form.validity_end || null,
            dayofweek_mask: form.dayofweek_mask === 127 ? null : form.dayofweek_mask,
            time_start: form.time_start ? `${form.time_start}:00` : null,
            time_end: form.time_end ? `${form.time_end}:00` : null,
            branch_scope_json: form.branch_scope_json,
            stackable: form.stackable,
            requires_manager_approval: form.requires_manager_approval,
        };

        let discount: Discount;
        if (modalMode.value === 'create') {
            const r = await createDiscount(payload);
            discount = r.data;
        } else if (modalTarget.value) {
            const r = await updateDiscount(modalTarget.value.uuid, payload);
            discount = r.data;
        } else {
            return;
        }

        // Sync targets when scope is product or category.
        if (form.scope === 'product' || form.scope === 'category') {
            const targets: { target_type: DiscountTargetType; target_id: number }[] = [];
            if (form.scope === 'product') {
                for (const id of form.target_product_ids) {
                    targets.push({ target_type: 'product', target_id: id });
                }
            }
            if (form.scope === 'category') {
                for (const id of form.target_category_ids) {
                    targets.push({ target_type: 'category', target_id: id });
                }
            }
            const r = await syncDiscountTargets(discount.uuid, { targets });
            discount = r.data;
        }

        if (modalMode.value === 'create') {
            discounts.value = [discount, ...discounts.value];
        } else {
            const idx = discounts.value.findIndex((d) => d.uuid === discount.uuid);
            if (idx >= 0) discounts.value[idx] = discount;
        }
        modalOpen.value = false;
    } catch (err) {
        if (err instanceof ApiError) {
            const p = err.payload as { message?: string } | null;
            modalError.value = p?.message ?? t('discounts.errors.save_failed');
        } else {
            modalError.value = t('discounts.errors.save_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

// ---- Pause / resume / delete -----------------------------------
async function togglePause(d: Discount): Promise<void> {
    try {
        const r = d.status === 'active' ? await pauseDiscount(d.uuid) : await resumeDiscount(d.uuid);
        const idx = discounts.value.findIndex((x) => x.uuid === r.data.uuid);
        if (idx >= 0) discounts.value[idx] = r.data;
    } catch {
        // surface in a toast in a future iteration; for now silent
    }
}

const toDelete = ref<Discount | null>(null);
const deleteBusy = ref(false);

async function performDelete(): Promise<void> {
    if (!toDelete.value) return;
    deleteBusy.value = true;
    try {
        await deleteDiscount(toDelete.value.uuid);
        discounts.value = discounts.value.filter((d) => d.uuid !== toDelete.value!.uuid);
        toDelete.value = null;
    } finally {
        deleteBusy.value = false;
    }
}

// ---- Display helpers -------------------------------------------
function statusBadgeClass(d: Discount): string {
    if (d.status === 'paused') return 'bg-amber-100 text-amber-700';
    if (d.status === 'expired') return 'bg-slate-200 text-slate-700';
    return d.currently_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
}

function statusLabel(d: Discount): string {
    if (d.status === 'paused') return t('discounts.statuses.paused');
    if (d.status === 'expired') return t('discounts.statuses.expired');
    return d.currently_active
        ? t('discounts.statuses.active_now')
        : t('discounts.statuses.scheduled');
}

function amountLabel(d: Discount): string {
    if (d.amount_type === 'percent') {
        return `${parseFloat(d.amount)}%`;
    }
    return `${d.amount} OMR`;
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <!-- Header strip -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('discounts.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('discounts.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('discounts.subtitle') }}
                    </p>
                </div>
                <div v-if="canManage">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-700"
                        @click="openCreate"
                    >
                        <Plus class="size-4" />
                        {{ t('discounts.actions.add') }}
                    </button>
                </div>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="discounts.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Tags class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('discounts.empty_state') }}</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.scope') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.amount') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.targets') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('discounts.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="d in discounts" :key="d.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ d.name }}</span>
                                    <span class="block text-xs text-slate-500">
                                        <span v-if="d.stackable" class="inline-block">{{ t('discounts.flags.stackable') }}</span>
                                        <span v-if="d.requires_manager_approval" class="inline-block ms-2">{{ t('discounts.flags.manager_approval') }}</span>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ t(`discounts.scopes.${d.scope}`) }}</td>
                                <td class="px-5 py-4 text-sm font-semibold text-slate-700">{{ amountLabel(d) }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <span v-if="d.scope === 'order'" class="text-slate-400">—</span>
                                    <span v-else>{{ d.targets_count ?? 0 }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(d)">
                                        {{ statusLabel(d) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <button v-if="canManage && d.status === 'active'" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50" @click="togglePause(d)">
                                            <Pause class="size-3.5" />
                                            {{ t('discounts.actions.pause') }}
                                        </button>
                                        <button v-else-if="canManage && d.status === 'paused'" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50" @click="togglePause(d)">
                                            <PlayCircle class="size-3.5" />
                                            {{ t('discounts.actions.resume') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="openEdit(d)">
                                            <Pencil class="size-3.5" />
                                            {{ t('discounts.actions.edit') }}
                                        </button>
                                        <button v-if="canManage" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50" @click="toDelete = d">
                                            <Trash2 class="size-3.5" />
                                            {{ t('discounts.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- ============== CREATE / EDIT MODAL =============== -->
        <div v-if="modalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ modalMode === 'create' ? t('discounts.modal.create_title') : t('discounts.modal.edit_title') }}
                    </h2>
                </div>
                <form class="space-y-5 p-6" @submit.prevent="submitModal">
                    <div v-if="modalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ modalError }}
                    </div>

                    <!-- Name + scope + amount -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">{{ t('discounts.fields.name') }}</label>
                            <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ t('discounts.fields.scope') }}</label>
                            <select v-model="form.scope" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="order">{{ t('discounts.scopes.order') }}</option>
                                <option value="product">{{ t('discounts.scopes.product') }}</option>
                                <option value="category">{{ t('discounts.scopes.category') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ t('discounts.fields.amount_type') }}</label>
                            <select v-model="form.amount_type" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <option value="percent">{{ t('discounts.amount_types.percent') }}</option>
                                <option value="fixed">{{ t('discounts.amount_types.fixed') }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">{{ t('discounts.fields.amount') }}</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input v-model="form.amount" type="text" inputmode="decimal" required class="block w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm font-mono shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                                <span class="text-sm text-slate-600">
                                    <Percent v-if="form.amount_type === 'percent'" class="inline size-3.5" />
                                    <span v-else>OMR</span>
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ t('discounts.fields.amount_hint') }}</p>
                        </div>
                    </div>

                    <!-- Targets picker (product / category scope only) -->
                    <fieldset v-if="form.scope === 'product'" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('discounts.fields.target_products') }}</legend>
                        <p class="text-xs text-slate-500">{{ t('discounts.fields.target_products_hint') }}</p>
                        <div class="max-h-48 overflow-y-auto grid grid-cols-2 gap-1">
                            <label v-for="p in products" :key="p.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-white">
                                <input v-model="form.target_product_ids" type="checkbox" :value="p.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="text-xs text-slate-700">{{ p.name }}</span>
                            </label>
                        </div>
                    </fieldset>
                    <fieldset v-if="form.scope === 'category'" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('discounts.fields.target_categories') }}</legend>
                        <div class="max-h-48 overflow-y-auto grid grid-cols-2 gap-1">
                            <label v-for="c in categories" :key="c.id" class="flex items-center gap-2 rounded px-2 py-1 hover:bg-white">
                                <input v-model="form.target_category_ids" type="checkbox" :value="c.id" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="text-xs text-slate-700">{{ c.name }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Validity window -->
                    <fieldset class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <legend class="px-2 text-sm font-semibold text-slate-700 inline-flex items-center gap-2">
                            <Calendar class="size-4 text-teal-700" />
                            {{ t('discounts.fields.validity') }}
                        </legend>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('discounts.fields.validity_start') }}</label>
                                <input v-model="form.validity_start" type="datetime-local" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('discounts.fields.validity_end') }}</label>
                                <input v-model="form.validity_end" type="datetime-local" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </div>

                        <!-- Day-of-week chips -->
                        <div>
                            <p class="block text-xs font-medium text-slate-600 mb-1">{{ t('discounts.fields.dayofweek') }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="d in DOW_BITS" :key="d.key" type="button" class="rounded-md border px-3 py-1 text-xs font-semibold transition" :class="isDowOn(d.bit) ? 'border-teal-300 bg-teal-100 text-teal-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'" @click="toggleDow(d.bit)">
                                    {{ t(`discounts.dow.${d.key}`) }}
                                </button>
                            </div>
                        </div>

                        <!-- Time-of-day -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('discounts.fields.time_start') }}</label>
                                <input v-model="form.time_start" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">{{ t('discounts.fields.time_end') }}</label>
                                <input v-model="form.time_end" type="time" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-500">{{ t('discounts.fields.time_hint') }}</p>
                    </fieldset>

                    <!-- Flags -->
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.stackable" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700">{{ t('discounts.fields.stackable') }}</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="form.requires_manager_approval" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700">{{ t('discounts.fields.requires_manager_approval') }}</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="modalOpen = false">
                            {{ t('common.cancel') }}
                        </button>
                        <button type="submit" :disabled="modalBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 disabled:opacity-50">
                            {{ modalBusy ? t('common.saving') : t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============== DELETE CONFIRM =============== -->
        <div v-if="toDelete" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('discounts.delete.title') }}</h2>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600">{{ t('discounts.delete.confirm', { name: toDelete.name }) }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="toDelete = null">{{ t('common.cancel') }}</button>
                        <button type="button" :disabled="deleteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 disabled:opacity-50" @click="performDelete">
                            {{ deleteBusy ? t('common.deleting') : t('discounts.actions.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

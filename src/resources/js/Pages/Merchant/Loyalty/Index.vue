<script setup lang="ts">
/**
 * Loyalty Rules — blueprint §5.8.
 *
 * Multi-rule loyalty config. A merchant defines visit_based
 * (stamp card) and/or spend_based (points) rules, multiple
 * active in parallel, each pause/resume-able. Per-customer
 * balances + adjustments live on the Customers page.
 *
 * Permission gating:
 *   - Page reachable when LoyaltyView
 *   - Add / Edit / Pause / Resume / Delete only when LoyaltyManage
 */

import { AlertTriangle, CheckCircle2, Coins, Gift, Pause, Pencil, Play, Plus, Stamp, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createLoyaltyRule,
    deleteLoyaltyRule,
    listLoyaltyRules,
    listLoyaltyShortfalls,
    pauseLoyaltyRule,
    resolveLoyaltyShortfall,
    resumeLoyaltyRule,
    updateLoyaltyRule,
    type LoyaltyRule,
    type LoyaltyRuleType,
    type LoyaltyShortfallAmounts,
    type LoyaltyShortfallReview,
    type LoyaltyShortfallStatus,
    type PaginatedLoyaltyShortfalls,
} from '@/lib/api/loyalty';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.LoyaltyManage));

const rules = ref<LoyaltyRule[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function fetchRules(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        rules.value = (await listLoyaltyRules()).data;
    } catch (err) {
        error.value = err instanceof ApiError ? `HTTP ${err.status}` : t('loyalty.errors.rules_load_failed');
    } finally {
        loading.value = false;
    }
}
const shortfallFilters: Array<LoyaltyShortfallStatus | 'all'> = ['pending', 'resolved', 'all'];

const shortfallFilter = ref<LoyaltyShortfallStatus | 'all'>('pending');
const shortfallPage = ref<PaginatedLoyaltyShortfalls | null>(null);
const shortfallLoading = ref(true);
const shortfallError = ref<string | null>(null);
const resolveTarget = ref<LoyaltyShortfallReview | null>(null);
const resolveNote = ref('');
const resolveBusy = ref(false);
const resolveError = ref<string | null>(null);
const shortfallRows = computed(() => shortfallPage.value?.data ?? []);
const shortfallMeta = computed(() => shortfallPage.value?.meta ?? null);
const resolutionNoteValid = computed(() => resolveNote.value.trim().length >= 3);

async function fetchShortfalls(page = 1): Promise<void> {
    shortfallLoading.value = true;
    shortfallError.value = null;
    try {
        shortfallPage.value = await listLoyaltyShortfalls(shortfallFilter.value, page);
    } catch (err) {
        shortfallError.value = err instanceof ApiError
            ? ((err.payload as { message?: string } | null)?.message ?? `HTTP ${err.status}`)
            : t('loyalty.errors.shortfalls_load_failed');
    } finally {
        shortfallLoading.value = false;
    }
}

function setShortfallFilter(status: LoyaltyShortfallStatus | 'all'): void {
    shortfallFilter.value = status;
    void fetchShortfalls(1);
}

function openResolve(row: LoyaltyShortfallReview): void {
    resolveTarget.value = row;
    resolveNote.value = '';
    resolveError.value = null;
}

async function submitShortfallResolution(): Promise<void> {
    if (!resolveTarget.value || !resolutionNoteValid.value) return;
    resolveBusy.value = true;
    resolveError.value = null;
    try {
        await resolveLoyaltyShortfall(resolveTarget.value.transaction_uuid, resolveNote.value.trim());
        resolveTarget.value = null;
        await fetchShortfalls(shortfallPage.value?.meta.current_page ?? 1);
    } catch (err) {
        resolveError.value = err instanceof ApiError
            ? ((err.payload as { message?: string } | null)?.message ?? `HTTP ${err.status}`)
            : t('loyalty.errors.shortfall_resolve_failed');
    } finally {
        resolveBusy.value = false;
    }
}

function displayDate(value: string | null): string {
    if (!value) return '-';
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
function displayAmounts(value: LoyaltyShortfallAmounts | null): string {
    if (value === null) return t('loyalty.shortfalls.amounts_unavailable');
    return t('loyalty.shortfalls.amounts', { points: value.points, stamps: value.stamps });
}

function closeResolve(): void {
    resolveTarget.value = null;
    resolveError.value = null;
}


onMounted(() => { void Promise.all([fetchRules(), fetchShortfalls()]); });

// ---- Modal ------------------------------------------------------
type ModalMode = 'create' | 'edit';
const modalOpen = ref(false);
const modalMode = ref<ModalMode>('create');
const modalBusy = ref(false);
const modalError = ref<string | null>(null);
const modalTarget = ref<LoyaltyRule | null>(null);

const form = reactive<{
    name: string;
    type: LoyaltyRuleType;
    // spend_based
    points_per_omr: number;
    redemption_points: number;
    redemption_value: string;
    min_redemption_points: number;
    // visit_based
    min_order_value: string;
    stamps_required: number;
    reward_type: string;
    reward_value: string;
}>({
    name: '',
    type: 'spend_based',
    points_per_omr: 1,
    redemption_points: 100,
    redemption_value: '5.000',
    min_redemption_points: 100,
    min_order_value: '2.000',
    stamps_required: 5,
    reward_type: 'free_product',
    reward_value: '',
});

function openCreate(): void {
    modalMode.value = 'create';
    modalTarget.value = null;
    Object.assign(form, {
        name: '', type: 'spend_based',
        points_per_omr: 1, redemption_points: 100, redemption_value: '5.000', min_redemption_points: 100,
        min_order_value: '2.000', stamps_required: 5, reward_type: 'free_product', reward_value: '',
    });
    modalError.value = null;
    modalOpen.value = true;
}

function openEdit(rule: LoyaltyRule): void {
    modalMode.value = 'edit';
    modalTarget.value = rule;
    const c = rule.config ?? {};
    Object.assign(form, {
        name: rule.name,
        type: rule.type,
        points_per_omr: Number(c.points_per_omr ?? 1),
        redemption_points: Number(c.redemption_points ?? 100),
        redemption_value: String(c.redemption_value ?? '5.000'),
        min_redemption_points: Number(c.min_redemption_points ?? 100),
        min_order_value: String(c.min_order_value ?? '2.000'),
        stamps_required: Number(c.stamps_required ?? 5),
        reward_type: String(c.reward_type ?? 'free_product'),
        reward_value: c.reward_value != null ? String(c.reward_value) : '',
    });
    modalError.value = null;
    modalOpen.value = true;
}

function buildConfig(): Record<string, unknown> {
    if (form.type === 'visit_based') {
        return {
            min_order_value: form.min_order_value,
            stamps_required: form.stamps_required,
            reward_type: form.reward_type,
            reward_value: form.reward_value || null,
        };
    }
    return {
        points_per_omr: form.points_per_omr,
        redemption_points: form.redemption_points,
        redemption_value: form.redemption_value,
        min_redemption_points: form.min_redemption_points,
    };
}

async function submit(): Promise<void> {
    modalBusy.value = true;
    modalError.value = null;
    try {
        if (modalMode.value === 'create') {
            const r = await createLoyaltyRule({ name: form.name, type: form.type, config_json: buildConfig() });
            rules.value = [r.data, ...rules.value];
        } else if (modalTarget.value) {
            const r = await updateLoyaltyRule(modalTarget.value.uuid, { name: form.name, config_json: buildConfig() });
            const idx = rules.value.findIndex((x) => x.uuid === r.data.uuid);
            if (idx >= 0) rules.value[idx] = r.data;
        }
        modalOpen.value = false;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            modalError.value = Object.values(err.payload.errors)[0]?.[0] ?? t('loyalty.errors.rule_save_failed');
        } else if (err instanceof ApiError) {
            const payload = err.payload as { message?: string } | null;
            modalError.value = payload?.message ?? t('loyalty.errors.rule_save_failed');
        } else {
            modalError.value = t('loyalty.errors.rule_save_failed');
        }
    } finally {
        modalBusy.value = false;
    }
}

async function toggle(rule: LoyaltyRule): Promise<void> {
    try {
        const r = rule.status === 'active' ? await pauseLoyaltyRule(rule.uuid) : await resumeLoyaltyRule(rule.uuid);
        const idx = rules.value.findIndex((x) => x.uuid === r.data.uuid);
        if (idx >= 0) rules.value[idx] = r.data;
    } catch {
        // surfaced via a toast in a future iteration
    }
}

const toDelete = ref<LoyaltyRule | null>(null);
const deleteBusy = ref(false);

async function performDelete(): Promise<void> {
    if (!toDelete.value) return;
    deleteBusy.value = true;
    try {
        await deleteLoyaltyRule(toDelete.value.uuid);
        rules.value = rules.value.filter((x) => x.uuid !== toDelete.value!.uuid);
        toDelete.value = null;
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <section class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">{{ t('loyalty.section_label') }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ t('loyalty.title') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ t('loyalty.subtitle') }}</p>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-700"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('loyalty.actions.add_rule') }}
                </button>
            </div>

            <section class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-amber-100 bg-amber-50/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="rounded-xl bg-amber-100 p-2 text-amber-700">
                            <AlertTriangle class="size-5" />
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">{{ t('loyalty.shortfalls.title') }}</h2>
                                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">
                                    {{ shortfallMeta?.total ?? 0 }}
                                </span>
                            </div>
                            <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ t('loyalty.shortfalls.subtitle') }}</p>
                        </div>
                    </div>
                    <div class="inline-flex self-start rounded-lg border border-amber-200 bg-white p-1 sm:self-auto">
                        <button
                            v-for="status in shortfallFilters"
                            :key="status"
                            type="button"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition"
                            :class="shortfallFilter === status ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-600 hover:bg-amber-50'"
                            :aria-pressed="shortfallFilter === status"
                            @click="setShortfallFilter(status)"
                        >
                            {{ t(`loyalty.shortfalls.filters.${status}`) }}
                        </button>
                    </div>
                </div>

                <div v-if="shortfallError" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700">
                    {{ shortfallError }}
                </div>
                <div v-if="shortfallLoading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>
                <div v-else-if="shortfallRows.length === 0" class="flex flex-col items-center gap-3 p-10 text-center text-slate-500">
                    <CheckCircle2 class="size-9 text-emerald-400" />
                    <p class="text-sm font-semibold">{{ t(`loyalty.shortfalls.empty.${shortfallFilter}`) }}</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.when') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.customer') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.order') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.requested') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.applied') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.shortfall') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.shortfalls.columns.review') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in shortfallRows" :key="row.transaction_uuid" class="align-top transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-4 text-sm tabular-nums text-slate-600">{{ displayDate(row.occurred_at) }}</td>
                                <td class="px-5 py-4 text-sm">
                                    <RouterLink
                                        v-if="row.customer"
                                        :to="`/customers/${row.customer.uuid}`"
                                        class="font-semibold text-teal-700 hover:text-teal-800 hover:underline"
                                    >
                                        {{ row.customer.name }}
                                    </RouterLink>
                                    <span v-else class="text-slate-400">{{ t('loyalty.shortfalls.unknown_customer') }}</span>
                                    <span v-if="row.rule" class="mt-1 block text-xs text-slate-500">{{ row.rule.name }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <span v-if="row.order" class="font-semibold">{{ row.order.receipt_number ?? `#${row.order.id}` }}</span>
                                    <span v-else class="text-slate-400">-</span>
                                    <span v-if="row.order?.status" class="mt-1 block text-xs capitalize text-slate-500">{{ row.order.status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm tabular-nums text-slate-700">{{ displayAmounts(row.requested) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm tabular-nums text-slate-700">{{ displayAmounts(row.applied) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold tabular-nums text-rose-700">{{ displayAmounts(row.shortfall) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div class="flex flex-col items-end gap-2">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="row.status === 'resolved' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                                        >
                                            <CheckCircle2 v-if="row.status === 'resolved'" class="size-3.5" />
                                            <AlertTriangle v-else class="size-3.5" />
                                            {{ t(`loyalty.shortfalls.status.${row.status}`) }}
                                        </span>
                                        <template v-if="row.resolution">
                                            <span class="max-w-xs text-xs text-slate-600">{{ row.resolution.note }}</span>
                                            <span class="text-xs text-slate-400">
                                                {{ row.resolution.resolved_by ?? t('loyalty.shortfalls.unknown_reviewer') }} / {{ displayDate(row.resolution.resolved_at) }}
                                            </span>
                                        </template>
                                        <button
                                            v-else-if="canManage"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100"
                                            @click="openResolve(row)"
                                        >
                                            <CheckCircle2 class="size-3.5" />
                                            {{ t('loyalty.shortfalls.actions.resolve') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="shortfallMeta && shortfallMeta.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <span>{{ shortfallMeta.current_page }} / {{ shortfallMeta.last_page }} / {{ shortfallMeta.total }}</span>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50"
                            :disabled="shortfallMeta.current_page <= 1 || shortfallLoading"
                            @click="fetchShortfalls(shortfallMeta.current_page - 1)"
                        >
                            {{ t('loyalty.shortfalls.pagination.previous') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50"
                            :disabled="shortfallMeta.current_page >= shortfallMeta.last_page || shortfallLoading"
                            @click="fetchShortfalls(shortfallMeta.current_page + 1)"
                        >
                            {{ t('loyalty.shortfalls.pagination.next') }}
                        </button>
                    </div>
                </div>
            </section>
            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">{{ t('common.loading') }}</div>
                <div v-else-if="rules.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Gift class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('loyalty.empty') }}</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.rules.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.rules.type') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.rules.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('loyalty.rules.accounts') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('customers.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="rule in rules" :key="rule.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-sm font-semibold text-slate-950">{{ rule.name }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center gap-1 text-sm text-slate-700">
                                        <Stamp v-if="rule.type === 'visit_based'" class="size-3.5 text-indigo-500" />
                                        <Coins v-else class="size-3.5 text-amber-500" />
                                        {{ t(`loyalty.types.${rule.type}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="rule.status === 'paused' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                                    >
                                        {{ t(`loyalty.statuses.${rule.status}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-end text-sm tabular-nums text-slate-500">{{ rule.accounts_count ?? 0 }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div v-if="canManage" class="inline-flex items-center gap-2">
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEdit(rule)">
                                            <Pencil class="size-3.5" /> {{ t('customers.actions.edit') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" @click="toggle(rule)">
                                            <Pause v-if="rule.status === 'active'" class="size-3.5" />
                                            <Play v-else class="size-3.5" />
                                            {{ rule.status === 'active' ? t('loyalty.actions.pause') : t('loyalty.actions.resume') }}
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50" @click="toDelete = rule">
                                            <Trash2 class="size-3.5" /> {{ t('customers.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <BaseModal
            v-if="resolveTarget"
            :title="t('loyalty.shortfalls.resolve_modal.title')"
            size="md"
            :loading="resolveBusy"
            @close="closeResolve"
        >
            <form id="loyalty-shortfall-resolve-form" class="space-y-4" @submit.prevent="submitShortfallResolution">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-slate-700">
                    <p class="font-semibold text-slate-950">
                        {{ resolveTarget.customer?.name ?? t('loyalty.shortfalls.unknown_customer') }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600">
                        {{ t('loyalty.shortfalls.resolve_modal.summary', {
                            requested: displayAmounts(resolveTarget.requested),
                            applied: displayAmounts(resolveTarget.applied),
                            shortfall: displayAmounts(resolveTarget.shortfall),
                        }) }}
                    </p>
                </div>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('loyalty.shortfalls.resolve_modal.note') }}
                    <textarea
                        v-model="resolveNote"
                        required
                        minlength="3"
                        maxlength="1000"
                        rows="4"
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        :placeholder="t('loyalty.shortfalls.resolve_modal.note_placeholder')"
                    ></textarea>
                </label>
                <p class="text-xs text-slate-500">{{ t('loyalty.shortfalls.resolve_modal.hint') }}</p>
                <p v-if="resolveError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ resolveError }}
                </p>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeResolve">
                        {{ t('common.cancel') }}
                    </button>
                    <button
                        type="submit"
                        form="loyalty-shortfall-resolve-form"
                        :disabled="resolveBusy || !resolutionNoteValid"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {{ resolveBusy ? t('common.saving') : t('loyalty.shortfalls.resolve_modal.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>
        <!-- Create / edit modal -->
        <BaseModal
            v-if="modalOpen"
            :title="modalMode === 'create' ? t('loyalty.modal.create_title') : t('loyalty.modal.edit_title')"
            size="lg"
            :loading="modalBusy"
            @close="modalOpen = false"
        >
            <form id="loyalty-rule-form" class="space-y-4" @submit.prevent="submit">
                    <div v-if="modalError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ modalError }}</div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('loyalty.rules.name') }}</label>
                        <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700">{{ t('loyalty.rules.type') }}</label>
                        <select v-model="form.type" :disabled="modalMode === 'edit'" class="mt-1 block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm shadow-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-100">
                            <option value="spend_based">{{ t('loyalty.types.spend_based') }}</option>
                            <option value="visit_based">{{ t('loyalty.types.visit_based') }}</option>
                        </select>
                        <p v-if="modalMode === 'edit'" class="mt-1 text-xs text-slate-400">{{ t('loyalty.modal.type_locked') }}</p>
                    </div>

                    <!-- spend_based config -->
                    <div v-if="form.type === 'spend_based'" class="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.points_per_omr') }}
                            <input v-model.number="form.points_per_omr" type="number" min="0" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.redemption_points') }}
                            <input v-model.number="form.redemption_points" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.redemption_value') }}
                            <input v-model="form.redemption_value" type="text" inputmode="decimal" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.min_redemption_points') }}
                            <input v-model.number="form.min_redemption_points" type="number" min="0" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                    </div>

                    <!-- visit_based config -->
                    <div v-else class="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.min_order_value') }}
                            <input v-model="form.min_order_value" type="text" inputmode="decimal" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-mono">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.stamps_required') }}
                            <input v-model.number="form.stamps_required" type="number" min="1" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.reward_type') }}
                            <select v-model="form.reward_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="free_product">{{ t('loyalty.config.reward_free_product') }}</option>
                                <option value="percent_off">{{ t('loyalty.config.reward_percent_off') }}</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-slate-600">{{ t('loyalty.config.reward_value') }}
                            <input v-model="form.reward_value" type="text" :placeholder="t('loyalty.config.reward_value_hint')" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                        </label>
                    </div>

            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="modalOpen = false">{{ t('common.cancel') }}</button>
                    <button type="submit" form="loyalty-rule-form" :disabled="modalBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">{{ modalBusy ? t('common.saving') : t('common.save') }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- Delete confirm -->
        <BaseModal
            v-if="toDelete"
            :title="t('loyalty.delete.title')"
            size="md"
            :loading="deleteBusy"
            @close="toDelete = null"
        >
            <p class="text-sm text-slate-600">{{ t('loyalty.delete.confirm', { name: toDelete.name }) }}</p>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="toDelete = null">{{ t('common.cancel') }}</button>
                    <button type="button" :disabled="deleteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50" @click="performDelete">{{ deleteBusy ? t('common.deleting') : t('customers.actions.delete') }}</button>
                </div>
            </template>
        </BaseModal>
    </MerchantLayout>
</template>

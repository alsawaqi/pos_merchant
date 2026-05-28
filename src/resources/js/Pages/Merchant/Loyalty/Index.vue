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

import { Coins, Gift, Pause, Pencil, Play, Plus, Stamp, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    createLoyaltyRule,
    deleteLoyaltyRule,
    listLoyaltyRules,
    pauseLoyaltyRule,
    resumeLoyaltyRule,
    updateLoyaltyRule,
    type LoyaltyRule,
    type LoyaltyRuleType,
} from '@/lib/api/loyalty';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
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

onMounted(() => { void fetchRules(); });

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

        <!-- Create / edit modal -->
        <div v-if="modalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ modalMode === 'create' ? t('loyalty.modal.create_title') : t('loyalty.modal.edit_title') }}</h2>
                </div>
                <form class="space-y-4 p-6" @submit.prevent="submit">
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

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="modalOpen = false">{{ t('common.cancel') }}</button>
                        <button type="submit" :disabled="modalBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50">{{ modalBusy ? t('common.saving') : t('common.save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete confirm -->
        <div v-if="toDelete" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 backdrop-blur-sm p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-200 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('loyalty.delete.title') }}</h2>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600">{{ t('loyalty.delete.confirm', { name: toDelete.name }) }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="toDelete = null">{{ t('common.cancel') }}</button>
                        <button type="button" :disabled="deleteBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:opacity-50" @click="performDelete">{{ deleteBusy ? t('common.deleting') : t('customers.actions.delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

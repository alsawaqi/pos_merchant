<script setup lang="ts">
/**
 * Branch Targets (P-G8) — sales goals per branch.
 *
 * A target is an amount per day / week / month evaluated over
 * back-to-back windows of N periods (cumulative: 200/day over a 3-day
 * window means 600 per window). The table shows each target's live
 * current-window progress + its last-12-window hit rate; expanding a
 * row reveals the window history. Creating a target for a branch
 * REPLACES its current active one (history kept). Only the amount and
 * the active flag are editable — structural changes are a replace.
 *
 * targets.manage-gated server-side; the dashboard's Branch Performance
 * widget is the read-only twin every user sees.
 */

import { Plus, Target } from 'lucide-vue-next';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { ApiError } from '@/lib/api';
import { listBranches, type Branch } from '@/lib/api/branches';
import {
    createBranchTarget,
    deleteBranchTarget,
    listBranchTargets,
    updateBranchTarget,
    type BranchTarget,
    type TargetPeriod,
} from '@/lib/api/branchTargets';

const { t } = useI18n();

const rows = ref<BranchTarget[]>([]);
const branches = ref<Branch[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const expandedUuid = ref<string | null>(null);

function apiMessage(e: unknown, fallback: string): string {
    if (e instanceof ApiError) {
        const payload = e.payload as { message?: string } | null;
        // `||`, not `??`: a bare server abort(403) carries message "" —
        // nullish coalescing would surface an empty (invisible) banner.
        return payload?.message || fallback;
    }
    return fallback;
}

async function fetchRows(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        rows.value = (await listBranchTargets()).data;
    } catch (e) {
        error.value = apiMessage(e, t('branch_targets.load_failed'));
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void fetchRows();
    void listBranches()
        .then((r) => { branches.value = r.data; })
        .catch(() => { /* picker degrades */ });
});

// ---- Create modal ---------------------------------------------------
const createOpen = ref(false);
const createBusy = ref(false);
const createError = ref<string | null>(null);
const createForm = reactive({
    branch_uuid: '',
    period: 'day' as TargetPeriod,
    amount: 100,
    window_periods: 1,
    starts_on: new Date().toISOString().slice(0, 10),
});

function openCreate(): void {
    createForm.branch_uuid = branches.value[0]?.uuid ?? '';
    createForm.period = 'day';
    createForm.amount = 100;
    createForm.window_periods = 1;
    createForm.starts_on = new Date().toISOString().slice(0, 10);
    createError.value = null;
    createOpen.value = true;
}

async function submitCreate(): Promise<void> {
    createBusy.value = true;
    createError.value = null;
    try {
        await createBranchTarget({ ...createForm });
        createOpen.value = false;
        await fetchRows();
    } catch (e) {
        createError.value = apiMessage(e, t('branch_targets.save_failed'));
    } finally {
        createBusy.value = false;
    }
}

// ---- Edit modal (amount / active only) -------------------------------
const editTarget = ref<BranchTarget | null>(null);
const editBusy = ref(false);
const editError = ref<string | null>(null);
const editForm = reactive({ amount: 0, is_active: true });

function openEdit(row: BranchTarget): void {
    editTarget.value = row;
    editForm.amount = Number(row.amount);
    editForm.is_active = row.is_active;
    editError.value = null;
}

async function submitEdit(): Promise<void> {
    const target = editTarget.value;
    if (target === null) return;
    editBusy.value = true;
    editError.value = null;
    try {
        await updateBranchTarget(target.uuid, { ...editForm });
        editTarget.value = null;
        await fetchRows();
    } catch (e) {
        editError.value = apiMessage(e, t('branch_targets.save_failed'));
    } finally {
        editBusy.value = false;
    }
}

async function remove(row: BranchTarget): Promise<void> {
    if (!window.confirm(t('branch_targets.delete_confirm'))) return;
    error.value = null;
    try {
        await deleteBranchTarget(row.uuid);
        await fetchRows();
    } catch (e) {
        error.value = apiMessage(e, t('branch_targets.save_failed'));
    }
}

function periodLabel(period: TargetPeriod): string {
    return t(`branch_targets.periods.${period}`);
}
</script>

<template>
    <MerchantLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-bold text-slate-950">
                        <Target class="size-6 text-teal-600" /> {{ t('branch_targets.title') }}
                    </h1>
                    <p class="text-sm text-slate-500">{{ t('branch_targets.subtitle') }}</p>
                </div>
                <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700" @click="openCreate">
                    <Plus class="size-4" /> {{ t('branch_targets.add') }}
                </button>
            </div>

            <p v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</p>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <p v-if="loading" class="px-5 py-6 text-sm text-slate-500">{{ t('branch_targets.loading') }}</p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3 text-start">{{ t('branch_targets.table.branch') }}</th>
                            <th class="px-2 py-3 text-start">{{ t('branch_targets.table.goal') }}</th>
                            <th class="px-2 py-3 text-start">{{ t('branch_targets.table.starts_on') }}</th>
                            <th class="px-2 py-3 text-start">{{ t('branch_targets.table.current') }}</th>
                            <th class="px-2 py-3 text-start">{{ t('branch_targets.table.hit_rate') }}</th>
                            <th class="px-2 py-3 text-start">{{ t('branch_targets.table.status') }}</th>
                            <th class="w-40 px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.uuid">
                            <tr class="border-b border-slate-50 hover:bg-slate-50">
                                <td class="px-5 py-3 font-semibold text-slate-900">{{ row.branch_name ?? '—' }}</td>
                                <td class="px-2 py-3 tabular-nums text-slate-700">
                                    {{ row.amount }} / {{ periodLabel(row.period) }}
                                    <span class="text-xs text-slate-500">× {{ row.window_periods }}</span>
                                </td>
                                <td class="px-2 py-3 text-slate-600">{{ row.starts_on }}</td>
                                <td class="px-2 py-3">
                                    <template v-if="row.current">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
                                                <div class="h-full rounded-full bg-teal-500" :style="{ width: `${Math.min(100, row.current.progress_pct)}%` }" />
                                            </div>
                                            <span class="text-xs tabular-nums text-slate-600">{{ row.current.actual }} / {{ row.current.goal }}</span>
                                        </div>
                                    </template>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                                <td class="px-2 py-3 text-xs font-semibold tabular-nums text-slate-600">
                                    {{ row.window_count > 0 ? t('branch_targets.hit_of', { hit: row.hit_count, total: row.window_count }) : '—' }}
                                </td>
                                <td class="px-2 py-3">
                                    <span :class="row.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" class="rounded-full px-2 py-0.5 text-[11px] font-bold">
                                        {{ row.is_active ? t('branch_targets.active') : t('branch_targets.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-end">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" class="rounded border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50" @click="expandedUuid = expandedUuid === row.uuid ? null : row.uuid">
                                            {{ t('branch_targets.history') }}
                                        </button>
                                        <button type="button" class="rounded border border-teal-200 px-2 py-1 text-[11px] font-semibold text-teal-700 hover:bg-teal-50" @click="openEdit(row)">
                                            {{ t('branch_targets.edit') }}
                                        </button>
                                        <button type="button" class="rounded border border-rose-200 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50" @click="remove(row)">
                                            {{ t('branch_targets.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expandedUuid === row.uuid">
                                <td colspan="7" class="bg-slate-50 px-5 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branch_targets.history_title') }}</p>
                                    <ul class="mt-2 space-y-1">
                                        <li v-for="w in row.history" :key="w.window_start" class="flex items-center gap-3 text-xs text-slate-600">
                                            <span :class="w.hit ? 'bg-emerald-500' : 'bg-rose-500'" class="size-2 rounded-full" />
                                            <span class="tabular-nums">{{ w.window_start }} → {{ w.window_end }}</span>
                                            <span class="font-semibold tabular-nums">{{ w.actual_amount }} / {{ w.goal_amount }}</span>
                                            <span :class="w.hit ? 'text-emerald-700' : 'text-rose-700'" class="font-bold">
                                                {{ w.hit ? t('branch_targets.hit') : t('branch_targets.missed') }}
                                            </span>
                                        </li>
                                        <li v-if="row.history.length === 0" class="text-xs text-slate-400">{{ t('branch_targets.no_history') }}</li>
                                    </ul>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="rows.length === 0">
                            <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">{{ t('branch_targets.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create -->
        <BaseModal v-if="createOpen" :title="t('branch_targets.add')" @close="createOpen = false">
            <form class="space-y-4" @submit.prevent="submitCreate">
                <p class="text-xs text-slate-500">{{ t('branch_targets.create_hint') }}</p>
                <p v-if="createError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ createError }}</p>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('branch_targets.fields.branch') }}
                    <select v-model="createForm.branch_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <option v-for="b in branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option>
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-slate-700">
                        {{ t('branch_targets.fields.amount') }}
                        <input v-model.number="createForm.amount" type="number" min="0.001" step="0.001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        {{ t('branch_targets.fields.period') }}
                        <select v-model="createForm.period" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <option value="day">{{ t('branch_targets.periods.day') }}</option>
                            <option value="week">{{ t('branch_targets.periods.week') }}</option>
                            <option value="month">{{ t('branch_targets.periods.month') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        {{ t('branch_targets.fields.window_periods') }}
                        <input v-model.number="createForm.window_periods" type="number" min="1" max="60" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        {{ t('branch_targets.fields.starts_on') }}
                        <input v-model="createForm.starts_on" type="date" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    </label>
                </div>
                <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    {{ t('branch_targets.window_goal_hint', { goal: (createForm.amount * createForm.window_periods).toFixed(3) }) }}
                </p>
                <button type="submit" :disabled="createBusy || !createForm.branch_uuid" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    {{ t('common.save') }}
                </button>
            </form>
        </BaseModal>

        <!-- Edit -->
        <BaseModal v-if="editTarget" :title="t('branch_targets.edit_title', { branch: editTarget.branch_name ?? '' })" @close="editTarget = null">
            <form class="space-y-4" @submit.prevent="submitEdit">
                <p class="text-xs text-slate-500">{{ t('branch_targets.edit_hint') }}</p>
                <p v-if="editError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">{{ editError }}</p>
                <label class="block text-sm font-semibold text-slate-700">
                    {{ t('branch_targets.fields.amount') }}
                    <input v-model.number="editForm.amount" type="number" min="0.001" step="0.001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                    <input v-model="editForm.is_active" type="checkbox" class="size-4 rounded border-slate-300">
                    {{ t('branch_targets.fields.is_active') }}
                </label>
                <button type="submit" :disabled="editBusy" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    {{ t('common.save') }}
                </button>
            </form>
        </BaseModal>
    </MerchantLayout>
</template>

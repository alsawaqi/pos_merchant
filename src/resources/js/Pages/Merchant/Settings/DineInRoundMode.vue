<script setup lang="ts">
/** Per-branch QR round handling; each select is an independent saved policy. */
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    getDineInRoundModeSetting,
    updateBranchDineInRoundMode,
    updateDineInRoundModeDefault,
    type BranchDineInRoundMode,
    type DineInRoundMode,
    type DineInRoundModeBranch,
    type DineInRoundModeSetting,
} from '@/lib/api/dineInRoundMode';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.BranchesUpdate));
const setting = ref<DineInRoundModeSetting | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const defaultSaving = ref(false);
const defaultError = ref<string | null>(null);
const defaultSuccess = ref(false);
const branchSaving = reactive<Record<string, boolean>>({});
const branchErrors = reactive<Record<string, string | null>>({});
const branchSuccess = reactive<Record<string, boolean>>({});
const modes = ['kitchen_direct', 'staff_confirm'] as const;

// Every PUT returns a full snapshot. Serialize mutation + application so a
// delayed response cannot overwrite another select's already-saved policy.
// Queued selects retain their own saving/error state; these are not a batch.
let saveQueue: Promise<void> = Promise.resolve();

function enqueueSave(save: () => Promise<void>): Promise<void> {
    const pending = saveQueue.then(save);
    saveQueue = pending.catch(() => {});
    return pending;
}

function apiErrorMessage(e: unknown): string {
    if (e instanceof ApiError) {
        if (e.status === 403) {
            return t('settings.dine_in_round_mode.forbidden');
        }
        const v = e.firstValidationMessage();
        if (v) {
            return v;
        }
        const payload = e.payload as { message?: unknown } | null;
        if (payload && typeof payload.message === 'string') {
            return payload.message;
        }
    }
    return t('settings.dine_in_round_mode.save_failed');
}

async function fetchSetting(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const response = await getDineInRoundModeSetting();
        setting.value = response.data;
    } catch (e) {
        loadError.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(() => { void fetchSetting(); });

async function changeDefault(event: Event): Promise<void> {
    if (!canManage.value || !setting.value?.company_default_editable || defaultSaving.value) {
        return;
    }
    const select = event.target as HTMLSelectElement;
    const mode = select.value as DineInRoundMode;
    defaultSaving.value = true;
    defaultError.value = null;
    defaultSuccess.value = false;
    try {
        await enqueueSave(async () => {
            const response = await updateDineInRoundModeDefault(mode);
            setting.value = response.data;
        });
        defaultSuccess.value = true;
    } catch (e) {
        defaultError.value = apiErrorMessage(e);
    } finally {
        defaultSaving.value = false;
        select.value = setting.value.company_default;
    }
}

async function changeBranch(branch: DineInRoundModeBranch, event: Event): Promise<void> {
    if (!canManage.value || branchSaving[branch.uuid]) {
        return;
    }
    const select = event.target as HTMLSelectElement;
    const mode = select.value as BranchDineInRoundMode;
    branchSaving[branch.uuid] = true;
    branchErrors[branch.uuid] = null;
    branchSuccess[branch.uuid] = false;
    try {
        await enqueueSave(async () => {
            const response = await updateBranchDineInRoundMode(branch.uuid, mode);
            setting.value = response.data;
        });
        branchSuccess[branch.uuid] = true;
    } catch (e) {
        branchErrors[branch.uuid] = apiErrorMessage(e);
    } finally {
        branchSaving[branch.uuid] = false;
        select.value = setting.value?.branches.find((row) => row.uuid === branch.uuid)?.mode ?? 'inherit';
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-4xl">
            <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.dine_in_round_mode.title') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('settings.dine_in_round_mode.subtitle') }}</p>

            <div v-if="loadError" role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="setting" class="space-y-6 p-4 sm:p-6">
                    <div>
                        <label for="dine-in-round-mode-default" class="block text-sm font-medium text-slate-700">
                            {{ t('settings.dine_in_round_mode.company_default_label') }}
                        </label>
                        <select
                            id="dine-in-round-mode-default"
                            data-testid="dine-in-round-mode-default"
                            :value="setting.company_default"
                            :disabled="!canManage || !setting.company_default_editable || defaultSaving"
                            class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-500"
                            @change="changeDefault"
                        >
                            <option v-for="mode in modes" :key="mode" :value="mode">{{ t(`settings.dine_in_round_mode.${mode}`) }}</option>
                        </select>
                        <p v-if="defaultSaving" role="status" class="mt-2 text-sm text-slate-500">{{ t('common.saving') }}</p>
                        <p v-if="defaultError" role="alert" class="mt-2 text-sm text-rose-600">{{ defaultError }}</p>
                        <p v-if="defaultSuccess" role="status" class="mt-2 text-sm text-emerald-700">{{ t('settings.dine_in_round_mode.save_success') }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <thead class="border-b border-slate-200 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-3 text-start">{{ t('settings.dine_in_round_mode.branch_column') }}</th>
                                    <th class="px-3 py-3 text-start">{{ t('settings.dine_in_round_mode.mode_column') }}</th>
                                    <th class="px-3 py-3 text-start">{{ t('settings.dine_in_round_mode.effective_column') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="branch in setting.branches" :key="branch.uuid" :data-testid="`dine-in-round-mode-branch-${branch.uuid}`">
                                    <td class="px-3 py-4 align-top">
                                        <p class="font-medium text-slate-800">{{ locale === 'ar' && branch.name_ar ? branch.name_ar : branch.name }}</p>
                                        <p class="mt-1 font-mono text-xs text-slate-500" dir="ltr">{{ branch.code }}</p>
                                    </td>
                                    <td class="min-w-64 px-3 py-4 align-top">
                                        <select
                                            :value="branch.mode ?? 'inherit'"
                                            :aria-label="`${t('settings.dine_in_round_mode.mode_column')}: ${branch.name}`"
                                            :disabled="!canManage || branchSaving[branch.uuid]"
                                            class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-500"
                                            @change="changeBranch(branch, $event)"
                                        >
                                            <option value="inherit">{{ t('settings.dine_in_round_mode.inherit') }}</option>
                                            <option v-for="mode in modes" :key="mode" :value="mode">{{ t(`settings.dine_in_round_mode.${mode}`) }}</option>
                                        </select>
                                        <p v-if="branchSaving[branch.uuid]" role="status" class="mt-2 text-sm text-slate-500">{{ t('common.saving') }}</p>
                                        <p v-if="branchErrors[branch.uuid]" role="alert" class="mt-2 text-sm text-rose-600">{{ branchErrors[branch.uuid] }}</p>
                                        <p v-if="branchSuccess[branch.uuid]" role="status" class="mt-2 text-sm text-emerald-700">{{ t('settings.dine_in_round_mode.save_success') }}</p>
                                    </td>
                                    <td class="px-3 py-4 align-top text-slate-600">{{ t(`settings.dine_in_round_mode.${branch.effective}`) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

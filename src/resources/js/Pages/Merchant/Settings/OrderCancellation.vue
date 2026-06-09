<script setup lang="ts">
/**
 * Order Cancellation policy — company-level setting (v2 #14).
 *
 * The merchant chooses which staff positions may cancel an order at
 * the POS. The Main POS reads the chosen set to gate the cancel
 * action at the terminal.
 *
 * Permission gating:
 *   - Page reachable + Save only when OrdersCancel. Without it the
 *     server returns 403 on both GET and PUT; the SPA also hides the
 *     form and the sidebar entry.
 */

import { Ban, ShieldX } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    getOrderCancellationSetting,
    updateOrderCancellationPositions,
} from '@/lib/api/orderCancellation';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.OrdersCancel));

const available = ref<string[]>([]);
const selected = ref<string[]>([]);

const loading = ref(true);
const loadError = ref<string | null>(null);

const saving = ref(false);
const saveError = ref<string | null>(null);
const saveSuccess = ref(false);

const canSave = computed(() => canManage.value && !saving.value && selected.value.length > 0);

function apiErrorMessage(e: unknown): string {
    if (e instanceof ApiError) {
        const v = e.firstValidationMessage();
        if (v) {
            return v;
        }
        const payload = e.payload as { message?: unknown } | null;
        if (payload && typeof payload.message === 'string') {
            return payload.message;
        }
    }
    return t('settings.order_cancellation.save_failed');
}

function isChecked(position: string): boolean {
    return selected.value.includes(position);
}

function toggle(position: string): void {
    // Toggling re-arms the form: clear any stale success/error state.
    saveSuccess.value = false;
    saveError.value = null;
    if (isChecked(position)) {
        selected.value = selected.value.filter((p) => p !== position);
    } else {
        selected.value = [...selected.value, position];
    }
}

async function fetchSetting(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await getOrderCancellationSetting();
        available.value = res.data.available_positions;
        selected.value = res.data.positions;
    } catch (e) {
        loadError.value = apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(() => { void fetchSetting(); });

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    saveError.value = null;
    saveSuccess.value = false;
    try {
        const res = await updateOrderCancellationPositions(selected.value);
        available.value = res.data.available_positions;
        selected.value = res.data.positions;
        saveSuccess.value = true;
    } catch (e) {
        saveError.value = apiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <MerchantLayout>
        <div class="mx-auto max-w-2xl">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.order_cancellation.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('settings.order_cancellation.subtitle') }}</p>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div v-if="!canManage && !loadError" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                {{ t('settings.order_cancellation.forbidden') }}
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else-if="available.length === 0" class="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <ShieldX class="size-8 text-slate-300" />
                    <p class="text-sm text-slate-500">{{ t('settings.order_cancellation.empty_state') }}</p>
                </div>
                <div v-else class="p-4 sm:p-6">
                    <p class="text-sm font-medium text-slate-700">{{ t('settings.order_cancellation.positions_label') }}</p>
                    <div class="mt-4 space-y-2">
                        <label
                            v-for="position in available"
                            :key="position"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-3 transition"
                            :class="canManage ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-60'"
                        >
                            <input
                                type="checkbox"
                                :checked="isChecked(position)"
                                :disabled="!canManage"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                @change="toggle(position)"
                            >
                            <span class="text-sm font-medium text-slate-700">{{ t(`pos_staff.positions.${position}`) }}</span>
                        </label>
                    </div>

                    <p v-if="canManage && selected.length === 0" class="mt-4 text-sm text-rose-600">
                        {{ t('settings.order_cancellation.at_least_one') }}
                    </p>
                    <p v-if="saveError" class="mt-4 text-sm text-rose-600">{{ saveError }}</p>
                    <p v-if="saveSuccess" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        {{ t('settings.order_cancellation.save_success') }}
                    </p>

                    <div v-if="canManage" class="mt-6 flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
                            :disabled="!canSave"
                            @click="save"
                        >
                            <Ban class="size-4" />
                            {{ saving ? t('common.saving') : t('settings.order_cancellation.save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

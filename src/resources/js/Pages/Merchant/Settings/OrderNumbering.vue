<script setup lang="ts">
/**
 * Order Numbering policy — company-level setting (P-F8).
 *
 * The merchant defines how POS order numbers look: a prefix plus a
 * zero-padded counter (e.g. KLD-0042), counted per BRANCH or once
 * across the COMPANY, optionally restarting each day. The server
 * (pos_api) owns the counter and allocates the next number at payment
 * time; the device prints it on the receipt. Offline devices fall back
 * to a local counter, so old/offline orders may show no number.
 *
 * Permission gating:
 *   - Page reachable + Save only with OrdersCancel (the shared
 *     POS-policy gate). Without it the server 403s on both GET and
 *     PUT; the SPA also hides the form and the sidebar entry.
 */

import { Hash } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    getOrderNumberingSetting,
    updateOrderNumberingSetting,
    type OrderNumberingSetting,
} from '@/lib/api/orderNumbering';
import { MerchantPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.OrdersCancel));

const form = reactive<OrderNumberingSetting>({
    enabled: false,
    prefix: '',
    pad: 4,
    scope: 'branch',
    daily_reset: false,
});

const loading = ref(true);
const loadError = ref<string | null>(null);

const saving = ref(false);
const saveError = ref<string | null>(null);
const saveSuccess = ref(false);

const padOptions = [3, 4, 5, 6] as const;

/** Live example of what an order number will look like (counter = 42). */
const preview = computed(() => `${form.prefix}${String(42).padStart(form.pad, '0')}`);

const canSave = computed(() => canManage.value && !saving.value);

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
    return t('settings.order_numbering.save_failed');
}

function applySetting(s: OrderNumberingSetting): void {
    form.enabled = s.enabled;
    form.prefix = s.prefix;
    form.pad = s.pad;
    form.scope = s.scope;
    form.daily_reset = s.daily_reset;
}

function touch(): void {
    // Any edit re-arms the form: clear stale success/error state.
    saveSuccess.value = false;
    saveError.value = null;
}

async function fetchSetting(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const res = await getOrderNumberingSetting();
        applySetting(res.data);
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
        const res = await updateOrderNumberingSetting({
            enabled: form.enabled,
            prefix: form.prefix.trim().slice(0, 8),
            pad: form.pad,
            scope: form.scope,
            daily_reset: form.daily_reset,
        });
        applySetting(res.data);
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
                <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.order_numbering.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('settings.order_numbering.subtitle') }}</p>
            </div>

            <div v-if="loadError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ loadError }}
            </div>

            <div v-if="!canManage && !loadError" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                {{ t('settings.order_numbering.forbidden') }}
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="px-4 py-12 text-center text-sm text-slate-400">{{ t('common.loading') }}</div>
                <div v-else class="space-y-5 p-4 sm:p-6">
                    <!-- Enable toggle -->
                    <label
                        class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 transition"
                        :class="canManage ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-60'"
                    >
                        <input
                            v-model="form.enabled"
                            type="checkbox"
                            :disabled="!canManage"
                            class="mt-0.5 size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            @change="touch"
                        >
                        <span>
                            <span class="block text-sm font-medium text-slate-700">{{ t('settings.order_numbering.enable_label') }}</span>
                            <span class="block text-xs text-slate-500">{{ t('settings.order_numbering.enable_hint') }}</span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2" :class="form.enabled ? '' : 'opacity-50'">
                        <!-- Prefix -->
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('settings.order_numbering.prefix_label') }}</span>
                            <input
                                v-model="form.prefix"
                                type="text"
                                maxlength="8"
                                placeholder="KLD-"
                                :disabled="!canManage"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 font-mono text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"
                                @input="touch"
                            >
                            <p class="mt-1 text-xs text-slate-500">{{ t('settings.order_numbering.prefix_hint') }}</p>
                        </label>

                        <!-- Pad width -->
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('settings.order_numbering.pad_label') }}</span>
                            <select
                                v-model.number="form.pad"
                                :disabled="!canManage"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"
                                @change="touch"
                            >
                                <option v-for="p in padOptions" :key="p" :value="p">
                                    {{ t('settings.order_numbering.pad_option', { digits: p, example: String(1).padStart(p, '0') }) }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <!-- Scope -->
                    <fieldset :class="form.enabled ? '' : 'opacity-50'">
                        <legend class="text-sm font-medium text-slate-700">{{ t('settings.order_numbering.scope_label') }}</legend>
                        <div class="mt-2 space-y-2">
                            <label
                                v-for="s in (['branch', 'company'] as const)"
                                :key="s"
                                class="flex items-start gap-3 rounded-lg border border-slate-200 px-3 py-3 transition"
                                :class="canManage ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-60'"
                            >
                                <input
                                    v-model="form.scope"
                                    type="radio"
                                    name="numbering-scope"
                                    :value="s"
                                    :disabled="!canManage"
                                    class="mt-0.5 size-4 border-slate-300 text-teal-600 focus:ring-teal-500"
                                    @change="touch"
                                >
                                <span>
                                    <span class="block text-sm font-medium text-slate-700">
                                        {{ s === 'branch' ? t('settings.order_numbering.scope_branch') : t('settings.order_numbering.scope_company') }}
                                    </span>
                                    <span class="block text-xs text-slate-500">
                                        {{ s === 'branch' ? t('settings.order_numbering.scope_branch_hint') : t('settings.order_numbering.scope_company_hint') }}
                                    </span>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Daily reset -->
                    <label
                        class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 transition"
                        :class="[canManage ? 'cursor-pointer hover:bg-slate-50' : 'cursor-not-allowed opacity-60', form.enabled ? '' : 'opacity-50']"
                    >
                        <input
                            v-model="form.daily_reset"
                            type="checkbox"
                            :disabled="!canManage"
                            class="mt-0.5 size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            @change="touch"
                        >
                        <span>
                            <span class="block text-sm font-medium text-slate-700">{{ t('settings.order_numbering.daily_reset_label') }}</span>
                            <span class="block text-xs text-slate-500">{{ t('settings.order_numbering.daily_reset_hint') }}</span>
                        </span>
                    </label>

                    <!-- Live preview -->
                    <div class="rounded-lg bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('settings.order_numbering.preview_label') }}</p>
                        <p class="mt-1 font-mono text-2xl font-bold tabular-nums" :class="form.enabled ? 'text-teal-700' : 'text-slate-400'" dir="ltr">
                            {{ preview }}
                        </p>
                    </div>

                    <p v-if="saveError" class="text-sm text-rose-600">{{ saveError }}</p>
                    <p v-if="saveSuccess" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                        {{ t('settings.order_numbering.save_success') }}
                    </p>

                    <div v-if="canManage" class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-60"
                            :disabled="!canSave"
                            @click="save"
                        >
                            <Hash class="size-4" />
                            {{ saving ? t('common.saving') : t('settings.order_numbering.save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MerchantLayout>
</template>

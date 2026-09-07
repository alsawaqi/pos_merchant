<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import {
    getQrTableCardsSetting, updateQrTableCardsSetting, tableCardsPrintUrl,
    type CardEnabled, type ScanGeofenceMode, type QrTableCardBranch, type QrTableCardsSetting,
} from '@/lib/api/qrTableCards';
import { MerchantPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(MerchantPermission.BranchesUpdate));
const canPrint = computed(() => can(MerchantPermission.FloorPlanView));
const setting = ref<QrTableCardsSetting | null>(null);
const loading = ref(true);
const loadError = ref(false);
const saving = reactive<Record<string, boolean>>({});
const errors = reactive<Record<string, boolean>>({});
const saved = reactive<Record<string, boolean>>({});
let saveQueue: Promise<void> = Promise.resolve();

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = false;
    try { setting.value = (await getQrTableCardsSetting()).data; }
    catch { loadError.value = true; }
    finally { loading.value = false; }
}
onMounted(load);

async function change(branch: QrTableCardBranch, key: 'card_enabled' | 'geofence_mode', event: Event): Promise<void> {
    if (!canManage.value || saving[branch.uuid]) return;
    const select = event.target as HTMLSelectElement;
    const enabled = key === 'card_enabled' ? select.value as CardEnabled : branch.card_enabled;
    const geofence = key === 'geofence_mode' ? select.value as ScanGeofenceMode : branch.geofence_mode;
    saving[branch.uuid] = true;
    errors[branch.uuid] = false;
    saved[branch.uuid] = false;
    const operation = saveQueue.then(async () => {
        setting.value = (await updateQrTableCardsSetting(branch.uuid, enabled, geofence)).data;
    });
    saveQueue = operation.catch(() => {});
    try { await operation; saved[branch.uuid] = true; }
    catch { errors[branch.uuid] = true; }
    finally {
        saving[branch.uuid] = false;
        select.value = setting.value?.branches.find(row => row.uuid === branch.uuid)?.[key] ?? branch[key];
    }
}
</script>

<template>
    <MerchantLayout>
        <main class="mx-auto max-w-4xl" data-testid="qr-table-cards-settings">
            <h1 class="text-2xl font-bold text-slate-900">{{ t('settings.qr_table_cards.title') }}</h1>
            <p class="mt-2 text-sm text-slate-500">{{ t('settings.qr_table_cards.subtitle') }}</p>
            <p v-if="loading" role="status" class="mt-6 text-sm text-slate-500">{{ t('common.loading') }}</p>
            <div v-if="loadError" role="alert" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {{ t('settings.qr_table_cards.failed') }}
                <button type="button" class="ms-3 underline" @click="load">{{ t('settings.qr_table_cards.retry') }}</button>
            </div>
            <template v-if="setting">
                <p v-if="!setting.web_base_url_configured" role="alert" class="mt-6 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 font-medium text-amber-900">
                    {{ t('settings.qr_table_cards.missing_url') }}
                </p>
                <section v-for="branch in setting.branches" :key="branch.uuid" :data-branch-uuid="branch.uuid"
                    class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="text-lg font-semibold text-slate-900">{{ locale === 'ar' && branch.name_ar ? branch.name_ar : branch.name }}</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('settings.qr_table_cards.card_label') }}
                            <select :value="branch.card_enabled" :disabled="!canManage || saving[branch.uuid]"
                                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"
                                @change="change(branch, 'card_enabled', $event)">
                                <option value="off">{{ t('settings.qr_table_cards.off') }}</option>
                                <option value="on">{{ t('settings.qr_table_cards.on') }}</option>
                            </select>
                        </label>
                        <label class="text-sm font-medium text-slate-700">
                            {{ t('settings.qr_table_cards.location_label') }}
                            <select :value="branch.geofence_mode" :disabled="!canManage || saving[branch.uuid]"
                                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"
                                @change="change(branch, 'geofence_mode', $event)">
                                <option v-for="mode in ['off', 'advisory', 'enforce']" :key="mode" :value="mode">{{ t('settings.qr_table_cards.' + mode) }}</option>
                            </select>
                        </label>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-slate-500">{{ t('settings.qr_table_cards.hint') }}</p>
                    <p v-if="!branch.fenced" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ t('settings.qr_table_cards.unfenced') }}</p>
                    <p v-if="saving[branch.uuid]" role="status" class="mt-3 text-sm text-slate-500">{{ t('common.saving') }}</p>
                    <p v-if="saved[branch.uuid]" role="status" class="mt-3 text-sm text-emerald-700">{{ t('settings.qr_table_cards.saved') }}</p>
                    <p v-if="errors[branch.uuid]" role="alert" class="mt-3 text-sm text-rose-700">{{ t('settings.qr_table_cards.failed') }}</p>
                    <a v-if="canPrint && setting.web_base_url_configured" :href="tableCardsPrintUrl(branch.uuid)" target="_blank" rel="noopener"
                        class="mt-5 inline-block rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">{{ t('settings.qr_table_cards.print') }}</a>
                </section>
            </template>
        </main>
    </MerchantLayout>
</template>

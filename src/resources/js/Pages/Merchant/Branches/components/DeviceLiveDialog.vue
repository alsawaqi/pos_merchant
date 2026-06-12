<script setup lang="ts">
/**
 * P-G9 — the merchant's restricted Live (scalefusion MDM) dialog for
 * one of their devices: live telemetry (RAM / storage / CPU / thermals
 * / battery / network / management / location) + EXACTLY four safe
 * actions — restart, shutdown, on-screen message, beep. The sharp MDM
 * verbs (lock / wipe / mark-lost / factory reset) are admin-only and
 * have no server endpoint from this portal at all.
 *
 * Slimmed port of pos_admin's DeviceScalefusionPanel: same donut
 * charts (plain inline SVG) + detail grids, but no GPS route history,
 * no remote mirror, no generic action modal. The location renders as
 * a keyless Google Maps embed (no JS-API dependency) + an external
 * link.
 */

import {
    BellRing, ExternalLink, MessageSquare, MonitorSmartphone, Power,
    RefreshCw, RotateCcw,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import {
    alarmLiveDevice, getDeviceLive, messageLiveDevice, rebootLiveDevice,
    shutdownLiveDevice, type DeviceLiveCommandResult, type ScalefusionDeviceDetail,
} from '@/lib/api/deviceLive';
import type { BranchDevice } from '@/lib/api/branches';
import { authState } from '@/stores/auth';
import { usePermissions } from '@/composables/usePermissions';
import { MerchantPermission } from '@/lib/permissions';

const props = defineProps<{ device: BranchDevice }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();
const { can } = usePermissions();

// view opens the dialog; the four levers need the control key too.
const canControl = can(MerchantPermission.DevicesLiveControl);

const kioskId = computed(() => (props.device.kiosk_id ?? '').trim());
const isEnrolled = computed(() => kioskId.value !== '');
const deviceName = computed(() => props.device.name ?? props.device.kiosk_id ?? '—');

// --- Detail fetch --------------------------------------------------
const raw = ref<ScalefusionDeviceDetail | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

// Scalefusion's single-device endpoint sometimes wraps the payload in
// a `device` envelope and sometimes returns it flat — unwrap either.
const d = computed<ScalefusionDeviceDetail | null>(() => raw.value?.device ?? raw.value ?? null);

async function load(): Promise<void> {
    if (!isEnrolled.value) return;
    loading.value = true;
    error.value = null;
    try {
        const response = await getDeviceLive(props.device.uuid);
        raw.value = response.data;
    } catch (err) {
        error.value = errorMessage(err, t('device_live.unreachable'));
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());

// --- Numeric helpers + telemetry -----------------------------------
function num(value: unknown): number | null {
    const n = typeof value === 'string' ? Number(value) : (value as number);
    return typeof n === 'number' && Number.isFinite(n) ? n : null;
}

// Assumed MB in → GB out once large enough. The percentage (the hero
// number) is unit-agnostic, so it's always correct.
function formatCapacity(value: number | null): string {
    if (value === null) return '—';
    return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`;
}

const CIRC = 2 * Math.PI * 40;
function dash(percent: number | null): string {
    const p = Math.max(0, Math.min(100, percent ?? 0));
    return `${(p / 100) * CIRC} ${CIRC}`;
}

const ramTotal = computed(() => num(d.value?.total_ram_size));
const ramUsed = computed(() => num(d.value?.ram_usage));
const ramFree = computed(() => (ramTotal.value !== null && ramUsed.value !== null ? ramTotal.value - ramUsed.value : null));
const ramPercent = computed(() => (ramTotal.value && ramUsed.value !== null ? Math.round((ramUsed.value / ramTotal.value) * 100) : null));

const storageTotal = computed(() => num(d.value?.storage_info?.total_internal_storage));
const storageAvail = computed(() => num(d.value?.storage_info?.total_internal_storage_avbl));
const storageUsed = computed(() => (storageTotal.value !== null && storageAvail.value !== null ? storageTotal.value - storageAvail.value : null));
const storagePercent = computed(() => (storageTotal.value && storageUsed.value !== null ? Math.round((storageUsed.value / storageTotal.value) * 100) : null));

const cpuUsage = computed(() => num(d.value?.cpu_usage));
const signal = computed(() => num(d.value?.sim_signal_strength));
const batteryHealth = computed(() => d.value?.battery_health ?? '—');
const batteryLevel = computed(() => num(d.value?.battery_status));

const temps = computed(() => [
    { key: 'cpu', label: t('device_live.cpu'), value: num(d.value?.cpu_temp_in_celsius), color: '#ef4444' },
    { key: 'battery', label: t('device_live.battery'), value: num(d.value?.battery_temp_in_celsius), color: '#f59e0b' },
    { key: 'screen', label: t('device_live.screen'), value: num(d.value?.screen_temp_in_celsius), color: '#3b82f6' },
]);
const hasTemps = computed(() => temps.value.some((row) => row.value !== null));

const wifiList = computed(() => (Array.isArray(d.value?.avbl_wifi_ssids) ? d.value!.avbl_wifi_ssids!.slice(0, 6) : []));
const wifiCount = computed(() => (Array.isArray(d.value?.avbl_wifi_ssids) ? d.value!.avbl_wifi_ssids!.length : 0));

const isOnline = computed(() => (d.value?.connection_status ?? '').toLowerCase() === 'online');
const isLocked = computed(() => d.value?.locked === true);
const isCharging = computed(() => d.value?.battery_charging === true);

const technical = computed(() => [
    { label: t('device_live.model_os'), value: [d.value?.model, d.value?.os_version ? `Android ${d.value?.os_version}` : null].filter(Boolean).join(' · ') || '—' },
    { label: t('device_live.wifi_ssid'), value: d.value?.connected_wifi_ssid ?? '—' },
    { label: t('device_live.ip_address'), value: d.value?.ip_address ?? '—' },
    { label: t('device_live.serial'), value: d.value?.build_serial_no ?? d.value?.serial_no ?? '—' },
    { label: t('device_live.app_version'), value: d.value?.app_version_name ?? '—' },
    { label: t('device_live.imei'), value: d.value?.imei_no ?? '—' },
]);

const network = computed(() => [
    { label: t('device_live.public_ip'), value: d.value?.public_ip ?? '—' },
    { label: t('device_live.phone'), value: d.value?.phone_no ?? '—' },
    { label: t('device_live.sim_network'), value: d.value?.sim_network ?? '—' },
    { label: t('device_live.network_type'), value: d.value?.sim1_network_type ?? '—' },
    { label: t('device_live.device_group'), value: d.value?.device_group?.name ?? '—' },
    { label: t('device_live.device_profile'), value: d.value?.device_profile?.name ?? '—' },
]);

const management = computed(() => [
    { label: t('device_live.enrollment_mode'), value: d.value?.management_details?.enrollment_mode ?? '—' },
    { label: t('device_live.enrollment_status'), value: d.value?.management_details?.enrollment_status ?? d.value?.enrollment_status ?? '—' },
    { label: t('device_live.management_state'), value: d.value?.management_details?.management_state ?? d.value?.management_state ?? '—' },
    { label: t('device_live.license_expiry'), value: d.value?.license?.expire_date ?? '—' },
    { label: t('device_live.battery_health'), value: batteryHealth.value },
    { label: t('device_live.attestation'), value: d.value?.device_attestation_status ?? '—' },
]);

// --- Location (keyless Google Maps embed — no JS-API dependency) ----
const lat = computed(() => num(d.value?.location?.lat));
const lng = computed(() => num(d.value?.location?.lng));
const latestAddress = computed(() => d.value?.location?.address ?? null);
const hasLocation = computed(() => lat.value !== null && lng.value !== null);
const mapEmbedUrl = computed(() => (hasLocation.value
    ? `https://www.google.com/maps?q=${lat.value},${lng.value}&z=15&output=embed`
    : null));
const mapLinkUrl = computed(() => (hasLocation.value
    ? `https://www.google.com/maps?q=${lat.value},${lng.value}`
    : null));

// --- Toast ---------------------------------------------------------
const toast = reactive({ visible: false, tone: 'success' as 'success' | 'error', title: '' });
let toastTimer: ReturnType<typeof setTimeout> | undefined;
function showToast(ok: boolean, actionLabel: string): void {
    toast.visible = true;
    toast.tone = ok ? 'success' : 'error';
    toast.title = t(ok ? 'device_live.toast_sent' : 'device_live.toast_failed', { action: actionLabel });
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.visible = false; }, 4200);
}

function errorMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object') {
        // The controller relays scalefusion failures as {data:{message}};
        // Laravel aborts (the kiosk-id 422) carry a top-level message. A
        // bare abort(403/404) carries message "" — hence the || fallback.
        const payload = err.payload as { message?: unknown; data?: { message?: unknown } };
        const message = payload.message ?? payload.data?.message;
        if (typeof message === 'string' && message !== '') return message;
        // Never show ApiError's generic English "Request failed with
        // status N" — the localized fallback reads better in both languages.
        return fallback;
    }
    return err instanceof Error && err.message !== '' ? err.message : fallback;
}

// --- Confirm-then-run for the three one-shot commands ---------------
type Runner = () => Promise<DeviceLiveCommandResult>;
const confirmState = reactive<{ open: boolean; label: string; loading: boolean; error: string | null; run: Runner | null }>(
    { open: false, label: '', loading: false, error: null, run: null },
);

function askConfirm(label: string, run: Runner): void {
    confirmState.open = true;
    confirmState.label = label;
    confirmState.error = null;
    confirmState.run = run;
}

async function confirmRun(): Promise<void> {
    if (!confirmState.run) return;
    confirmState.loading = true;
    confirmState.error = null;
    try {
        const result = await confirmState.run();
        confirmState.open = false;
        showToast(result.ok, confirmState.label);
        void load();
    } catch (err) {
        confirmState.error = errorMessage(err, t('device_live.toast_failed', { action: confirmState.label }));
    } finally {
        confirmState.loading = false;
    }
}

const uuid = computed(() => props.device.uuid);
function doRestart(): void { askConfirm(t('device_live.restart'), () => rebootLiveDevice(uuid.value)); }
function doShutdown(): void { askConfirm(t('device_live.shutdown'), () => shutdownLiveDevice(uuid.value)); }
function doBeep(): void { askConfirm(t('device_live.beep'), () => alarmLiveDevice(uuid.value)); }

// --- On-screen message modal ----------------------------------------
const messageOpen = ref(false);
const messageBusy = ref(false);
const messageError = ref<string | null>(null);
const messageForm = reactive({
    sender_name: authState.user?.name ?? '',
    message_body: '',
    keep_ringing: true,
    show_as_dialog: true,
});

async function sendMessage(): Promise<void> {
    if (!messageForm.sender_name.trim() || !messageForm.message_body.trim()) return;
    messageBusy.value = true;
    messageError.value = null;
    try {
        const result = await messageLiveDevice(uuid.value, {
            sender_name: messageForm.sender_name.trim(),
            message_body: messageForm.message_body.trim(),
            keep_ringing: messageForm.keep_ringing,
            show_as_dialog: messageForm.show_as_dialog,
        });
        messageOpen.value = false;
        messageForm.message_body = '';
        showToast(result.ok, t('device_live.message'));
    } catch (err) {
        messageError.value = errorMessage(err, t('device_live.toast_failed', { action: t('device_live.message') }));
    } finally {
        messageBusy.value = false;
    }
}
</script>

<template>
    <!-- Escape + dismissal stay with the INNERMOST open modal: while a
         confirm/composer is up (or a command is in flight) the outer
         dialog must not react to Escape, or one keypress would tear the
         whole telemetry view down mid-command. -->
    <BaseModal
        :title="t('device_live.title', { name: deviceName })"
        size="4xl"
        :close-on-esc="!confirmState.open && !messageOpen"
        :close-on-backdrop="!confirmState.open && !messageOpen"
        :loading="confirmState.loading || messageBusy"
        @close="emit('close')"
    >
        <div class="space-y-5">
            <!-- Not enrolled / loading / error states -->
            <div v-if="!isEnrolled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                {{ t('device_live.not_enrolled') }}
            </div>

            <template v-else>
                <div v-if="loading && !d" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('device_live.loading') }}
                </div>
                <div v-else-if="error && !d" class="flex flex-col items-center gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-6">
                    <p class="text-sm font-semibold text-rose-700">{{ error }}</p>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100" @click="load">
                        <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" /> {{ t('device_live.refresh') }}
                    </button>
                </div>

                <template v-else-if="d">
                    <!-- A failed REFRESH keeps the stale telemetry usable
                         and surfaces the error as a banner instead. -->
                    <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ error }}
                    </div>
                    <!-- Header: status badges + the four safe actions -->
                    <header class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-center gap-4">
                            <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-slate-950 text-white">
                                <MonitorSmartphone class="size-5" />
                            </div>
                            <div>
                                <p class="text-base font-semibold text-slate-950">{{ d.name ?? deviceName }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-semibold">
                                    <span :class="isOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'" class="rounded-full px-2.5 py-1">
                                        {{ isOnline ? t('device_live.online') : t('device_live.offline') }}
                                    </span>
                                    <span v-if="isLocked" class="rounded-full bg-rose-100 px-2.5 py-1 text-rose-700">{{ t('device_live.locked') }}</span>
                                    <span v-if="batteryLevel !== null" class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ batteryLevel }}%</span>
                                    <span v-if="isCharging" class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-700">{{ t('device_live.charging') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50" :title="t('device_live.refresh')" @click="load">
                                <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
                            </button>
                            <template v-if="canControl">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-50" @click="doRestart">
                                    <RotateCcw class="size-4" /> {{ t('device_live.restart') }}
                                </button>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50" @click="doShutdown">
                                    <Power class="size-4" /> {{ t('device_live.shutdown') }}
                                </button>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="messageOpen = true">
                                    <MessageSquare class="size-4" /> {{ t('device_live.message') }}
                                </button>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="doBeep">
                                    <BellRing class="size-4" /> {{ t('device_live.beep') }}
                                </button>
                            </template>
                        </div>
                    </header>

                    <!-- Telemetry: RAM + storage donuts + thermal -->
                    <div class="grid gap-4 lg:grid-cols-3">
                        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.ram') }}</h3>
                            <div v-if="ramPercent !== null" class="mt-3 flex items-center gap-4">
                                <svg viewBox="0 0 100 100" class="size-24 shrink-0">
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#dbeafe" stroke-width="12" />
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#2563eb" stroke-width="12" stroke-linecap="round" :stroke-dasharray="dash(ramPercent)" transform="rotate(-90 50 50)" />
                                    <text x="50" y="52" text-anchor="middle" dominant-baseline="middle" class="fill-slate-950 text-[20px] font-bold">{{ ramPercent }}%</text>
                                </svg>
                                <dl class="space-y-1.5 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-blue-600" /><dt class="text-slate-500">{{ t('device_live.used') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramUsed) }}</dd></div>
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-blue-200" /><dt class="text-slate-500">{{ t('device_live.free') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramFree) }}</dd></div>
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-slate-400" /><dt class="text-slate-500">{{ t('device_live.total') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramTotal) }}</dd></div>
                                </dl>
                            </div>
                            <p v-else class="mt-3 text-sm text-slate-500">{{ t('device_live.no_data') }}</p>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.storage') }}</h3>
                            <div v-if="storagePercent !== null" class="mt-3 flex items-center gap-4">
                                <svg viewBox="0 0 100 100" class="size-24 shrink-0">
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#99f6e4" stroke-width="12" />
                                    <circle cx="50" cy="50" r="40" fill="none" stroke="#0f766e" stroke-width="12" stroke-linecap="round" :stroke-dasharray="dash(storagePercent)" transform="rotate(-90 50 50)" />
                                    <text x="50" y="52" text-anchor="middle" dominant-baseline="middle" class="fill-slate-950 text-[20px] font-bold">{{ storagePercent }}%</text>
                                </svg>
                                <dl class="space-y-1.5 text-sm">
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-teal-700" /><dt class="text-slate-500">{{ t('device_live.used') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageUsed) }}</dd></div>
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-teal-300" /><dt class="text-slate-500">{{ t('device_live.available') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageAvail) }}</dd></div>
                                    <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-slate-400" /><dt class="text-slate-500">{{ t('device_live.total') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageTotal) }}</dd></div>
                                </dl>
                            </div>
                            <p v-else class="mt-3 text-sm text-slate-500">{{ t('device_live.no_data') }}</p>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.thermal') }}</h3>
                            <div v-if="hasTemps" class="mt-3 space-y-2.5">
                                <div v-for="row in temps" :key="row.key" class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2"><span class="size-2.5 rounded-full" :style="{ backgroundColor: row.color }" />{{ row.label }}</span>
                                    <span class="font-semibold text-slate-900">{{ row.value !== null ? `${row.value}°C` : '—' }}</span>
                                </div>
                            </div>
                            <p v-else class="mt-3 text-sm text-slate-500">{{ t('device_live.no_data') }}</p>
                        </section>
                    </div>

                    <!-- Stat cards -->
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.cpu_load') }}</p>
                            <p class="mt-1.5 text-xl font-bold text-slate-950">{{ cpuUsage !== null ? `${cpuUsage}%` : '—' }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.battery_health') }}</p>
                            <p class="mt-1.5 text-xl font-bold text-slate-950">{{ batteryHealth }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.signal_strength') }}</p>
                            <p class="mt-1.5 text-xl font-bold text-slate-950">{{ signal !== null ? `${signal}/4` : '—' }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.connectivity') }}</p>
                            <p class="mt-1.5 text-xl font-bold text-slate-950">{{ wifiCount }} Wi-Fi</p>
                        </div>
                    </div>

                    <!-- Detail grids: technical / network / management -->
                    <div class="grid gap-4 lg:grid-cols-3">
                        <section v-for="block in [{ title: t('device_live.technical'), rows: technical }, { title: t('device_live.network'), rows: network }, { title: t('device_live.management'), rows: management }]" :key="block.title" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ block.title }}</h3>
                            <dl class="mt-3 space-y-2.5 text-sm">
                                <div v-for="row in block.rows" :key="row.label">
                                    <dt class="font-medium text-slate-500">{{ row.label }}</dt>
                                    <dd class="break-words font-semibold text-slate-900">{{ row.value }}</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <!-- Location: keyless embed + external link -->
                    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.location') }}</h3>
                                <p v-if="latestAddress" class="mt-1 text-sm text-slate-600">{{ t('device_live.latest_address') }}: {{ latestAddress }}</p>
                                <p v-if="hasLocation" class="mt-0.5 font-mono text-xs text-blue-600">{{ lat?.toFixed(5) }}, {{ lng?.toFixed(5) }}</p>
                            </div>
                            <a
                                v-if="mapLinkUrl"
                                :href="mapLinkUrl"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                <ExternalLink class="size-4" /> {{ t('device_live.open_in_maps') }}
                            </a>
                        </div>
                        <iframe
                            v-if="mapEmbedUrl"
                            :src="mapEmbedUrl"
                            class="mt-4 h-72 w-full rounded-xl border border-slate-200"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            title="Device location"
                        />
                        <p v-else class="mt-3 text-sm text-slate-500">{{ t('device_live.no_location') }}</p>
                    </section>

                    <!-- Nearby Wi-Fi -->
                    <section v-if="wifiList.length" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_live.connectivity') }} ({{ wifiCount }})</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="ssid in wifiList" :key="ssid" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700">{{ ssid }}</span>
                        </div>
                    </section>
                </template>
            </template>
        </div>

        <!-- Confirm dialog for the one-shot commands -->
        <BaseModal
            v-if="confirmState.open"
            :title="t('device_live.confirm_title', { action: confirmState.label })"
            size="sm"
            :loading="confirmState.loading"
            @close="confirmState.open = false"
        >
            <div v-if="confirmState.error" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ confirmState.error }}</div>
            <p class="text-sm text-slate-700">{{ t('device_live.confirm_message', { action: confirmState.label }) }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="confirmState.loading" @click="confirmState.open = false">{{ t('device_live.cancel') }}</button>
                    <button type="button" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-70" :disabled="confirmState.loading" @click="confirmRun">{{ confirmState.label }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- On-screen message modal -->
        <BaseModal v-if="messageOpen" :title="t('device_live.message_title')" size="lg" :loading="messageBusy" @close="messageOpen = false">
            <p class="mb-4 text-sm text-slate-500">{{ t('device_live.message_hint') }}</p>
            <div v-if="messageError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ messageError }}</div>
            <form id="device-live-message-form" class="space-y-4" @submit.prevent="sendMessage">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_live.sender') }}</span>
                    <input v-model="messageForm.sender_name" type="text" maxlength="100" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_live.body') }}</span>
                    <textarea v-model="messageForm.message_body" rows="4" maxlength="1000" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="messageForm.keep_ringing" type="checkbox"> {{ t('device_live.keep_ringing') }}</label>
                    <label class="flex items-center gap-2"><input v-model="messageForm.show_as_dialog" type="checkbox"> {{ t('device_live.show_as_dialog') }}</label>
                </div>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="messageBusy" @click="messageOpen = false">{{ t('device_live.cancel') }}</button>
                    <button type="submit" form="device-live-message-form" :disabled="messageBusy" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-70">{{ t('device_live.send') }}</button>
                </div>
            </template>
        </BaseModal>

        <!-- Toast -->
        <Teleport to="body">
            <div v-if="toast.visible" class="fixed end-6 top-6 z-[70] flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg" :class="toast.tone === 'success' ? 'bg-emerald-600' : 'bg-rose-600'">
                {{ toast.title }}
            </div>
        </Teleport>
    </BaseModal>
</template>

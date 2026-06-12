/**
 * P-G9 — the restricted device Live (scalefusion MDM) surface.
 *
 * One read (the raw v3 telemetry the Live dialog renders) + EXACTLY
 * four safe commands. The server hard-codes the whitelist — there is
 * no generic action endpoint to call even by hand-crafting a request.
 * All endpoints are devices.live-gated and F5 branch-scoped.
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

/**
 * Loose mirror of scalefusion's v3 single-device payload — only the
 * fields the dialog reads are typed; the endpoint sometimes wraps the
 * payload in a `device` envelope and sometimes returns it flat.
 */
export interface ScalefusionDeviceDetail {
    device?: ScalefusionDeviceDetail;
    id?: number;
    name?: string | null;
    total_ram_size?: number | string | null;
    ram_usage?: number | string | null;
    storage_info?: {
        total_internal_storage?: number | string | null;
        total_internal_storage_avbl?: number | string | null;
    } | null;
    cpu_usage?: number | string | null;
    sim_signal_strength?: number | string | null;
    battery_health?: string | null;
    battery_status?: number | string | null;
    battery_charging?: boolean | null;
    cpu_temp_in_celsius?: number | string | null;
    battery_temp_in_celsius?: number | string | null;
    screen_temp_in_celsius?: number | string | null;
    avbl_wifi_ssids?: string[] | null;
    connection_status?: string | null;
    locked?: boolean | null;
    model?: string | null;
    app_version_name?: string | null;
    os_version?: string | null;
    connected_wifi_ssid?: string | null;
    ip_address?: string | null;
    build_serial_no?: string | null;
    serial_no?: string | null;
    imei_no?: string | null;
    public_ip?: string | null;
    phone_no?: string | null;
    sim_network?: string | null;
    sim1_network_type?: string | null;
    device_group?: { name?: string | null } | null;
    device_profile?: { name?: string | null } | null;
    management_details?: {
        enrollment_mode?: string | null;
        enrollment_status?: string | null;
        management_state?: string | null;
    } | null;
    enrollment_status?: string | null;
    management_state?: string | null;
    license?: { expire_date?: string | null } | null;
    device_attestation_status?: string | null;
    location?: {
        lat?: number | string | null;
        lng?: number | string | null;
        address?: string | null;
        date_time?: string | null;
    } | null;
}

export interface DeviceLiveCommandResult {
    ok: boolean;
    data: unknown;
}

export interface DeviceLiveMessagePayload {
    sender_name: string;
    message_body: string;
    keep_ringing?: boolean;
    show_as_dialog?: boolean;
}

export function getDeviceLive(uuid: string): Promise<{ data: ScalefusionDeviceDetail }> {
    return apiGet<{ data: ScalefusionDeviceDetail }>(`/api/devices/${uuid}/live`);
}

export function rebootLiveDevice(uuid: string): Promise<DeviceLiveCommandResult> {
    return apiPost<DeviceLiveCommandResult>(`/api/devices/${uuid}/live/reboot`, {});
}

export function shutdownLiveDevice(uuid: string): Promise<DeviceLiveCommandResult> {
    return apiPost<DeviceLiveCommandResult>(`/api/devices/${uuid}/live/shutdown`, {});
}

export function alarmLiveDevice(uuid: string): Promise<DeviceLiveCommandResult> {
    return apiPost<DeviceLiveCommandResult>(`/api/devices/${uuid}/live/alarm`, {});
}

export function messageLiveDevice(
    uuid: string,
    payload: DeviceLiveMessagePayload,
): Promise<DeviceLiveCommandResult> {
    return apiPost<DeviceLiveCommandResult>(
        `/api/devices/${uuid}/live/message`,
        payload as unknown as JsonValue,
    );
}

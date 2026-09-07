import { apiDownload, apiGet, apiPut } from '@/lib/api';

export type CardEnabled = 'off' | 'on';
export type ScanGeofenceMode = 'off' | 'advisory' | 'enforce';
export interface QrTableCardBranch {
    uuid: string;
    name: string;
    name_ar: string | null;
    card_enabled: CardEnabled;
    geofence_mode: ScanGeofenceMode;
    fenced: boolean;
}
export interface QrTableCardsSetting {
    web_base_url_configured: boolean;
    branches: QrTableCardBranch[];
}
export const getQrTableCardsSetting = () => apiGet<{ data: QrTableCardsSetting }>('/api/settings/qr-table-cards');
export const updateQrTableCardsSetting = (uuid: string, card_enabled: CardEnabled, geofence_mode: ScanGeofenceMode) =>
    apiPut<{ data: QrTableCardsSetting }>('/api/settings/qr-table-cards/branches/' + encodeURIComponent(uuid), { card_enabled, geofence_mode });
export function tableCardsPrintUrl(branchUuid: string, tableUuid?: string): string {
    return '/print/table-cards/' + encodeURIComponent(branchUuid)
        + (tableUuid ? '#table-' + encodeURIComponent(tableUuid) : '');
}

/** Reuse the authorised print sheet's exact Bacon SVG; no second renderer or endpoint. */
export async function getTableCardPreview(branchUuid: string, tableUuid: string): Promise<{ svg: string; url: string } | null> {
    const { blob } = await apiDownload(tableCardsPrintUrl(branchUuid));
    const document = new DOMParser().parseFromString(await blob.text(), 'text/html');
    const card = Array.from(document.querySelectorAll<HTMLElement>('[data-table-uuid]'))
        .find(element => element.dataset.tableUuid === tableUuid);
    const svg = card?.querySelector('.qr svg');
    const url = card?.querySelector('.card-url')?.textContent?.trim();
    return svg && url ? { svg: svg.outerHTML, url } : null;
}

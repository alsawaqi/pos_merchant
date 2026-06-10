/**
 * Manager Approval policy API — company-level setting (P-F1).
 *
 * Defines which staff positions may authorize sensitive POS actions
 * (comps, cancellations, gifts) by entering their PIN — the manager
 * fingerprint fallback. pos_api emits the chosen set in /device/config
 * and verifies PINs against it on /device/auth/verify-manager-pin.
 *
 * Server gate: orders.cancel for BOTH read and write (403 otherwise).
 * `positions` is the chosen subset; `available_positions` is the full
 * catalogue (cashier / waiter / kitchen / manager / supervisor).
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';

export interface ManagerApprovalSetting {
    /** The staff positions whose PIN currently authorizes gated actions. */
    positions: string[];
    /** The full catalogue of selectable positions. */
    available_positions: string[];
}

export function getManagerApprovalSetting(): Promise<{ data: ManagerApprovalSetting }> {
    return apiGet<{ data: ManagerApprovalSetting }>('/api/settings/manager-approval');
}

export function updateManagerApprovalPositions(positions: string[]): Promise<{ data: ManagerApprovalSetting }> {
    return apiPut<{ data: ManagerApprovalSetting }>('/api/settings/manager-approval', { positions } as unknown as JsonValue);
}

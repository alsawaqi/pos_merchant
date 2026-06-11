/**
 * Device Reports access policy API — company-level setting (P-F6).
 *
 * Defines which staff positions may open the Reports dashboard on the
 * POS device (branch sales, tenders, top products, consumption).
 * pos_api emits the chosen set in /device/config and the DEVICE gates
 * its Reports screen on it.
 *
 * Server gate: orders.cancel for BOTH read and write (403 otherwise) —
 * the precedent set by the sibling position policies on this page.
 * `positions` is the chosen subset; `available_positions` is the full
 * catalogue (cashier / waiter / kitchen / manager / supervisor).
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';

export interface ReportsPositionsSetting {
    /** The staff positions currently allowed to open device reports. */
    positions: string[];
    /** The full catalogue of selectable positions. */
    available_positions: string[];
}

export function getReportsPositionsSetting(): Promise<{ data: ReportsPositionsSetting }> {
    return apiGet<{ data: ReportsPositionsSetting }>('/api/settings/reports-positions');
}

export function updateReportsPositions(positions: string[]): Promise<{ data: ReportsPositionsSetting }> {
    return apiPut<{ data: ReportsPositionsSetting }>('/api/settings/reports-positions', { positions } as unknown as JsonValue);
}

/**
 * Device Kitchen-section access policy API — company-level setting (P-G1).
 *
 * Defines which staff positions may open the Kitchen production screen on
 * the POS device (start / finish / cancel cooked-product batches).
 * pos_api emits the chosen set in /device/config and the DEVICE gates its
 * Kitchen screen on it — the exact reports_positions pattern.
 *
 * Server gate: orders.cancel for BOTH read and write (403 otherwise) —
 * the precedent set by the sibling position policies on this page.
 * `positions` is the chosen subset; `available_positions` is the full
 * catalogue (cashier / waiter / kitchen / manager / supervisor).
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';

export interface KitchenPositionsSetting {
    /** The staff positions currently allowed to open the device Kitchen screen. */
    positions: string[];
    /** The full catalogue of selectable positions. */
    available_positions: string[];
}

export function getKitchenPositionsSetting(): Promise<{ data: KitchenPositionsSetting }> {
    return apiGet<{ data: KitchenPositionsSetting }>('/api/settings/kitchen-positions');
}

export function updateKitchenPositions(positions: string[]): Promise<{ data: KitchenPositionsSetting }> {
    return apiPut<{ data: KitchenPositionsSetting }>('/api/settings/kitchen-positions', { positions } as unknown as JsonValue);
}

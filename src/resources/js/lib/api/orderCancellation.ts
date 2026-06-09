/**
 * Order Cancellation policy API — company-level setting (v2 #14).
 *
 * Defines which staff positions may cancel an order at the POS. The
 * Main POS reads the chosen set to gate the cancel action at the
 * terminal.
 *
 * Server gate: orders.cancel for BOTH read and write (403 otherwise).
 * `positions` is the chosen subset; `available_positions` is the full
 * catalogue (cashier / waiter / kitchen / manager / supervisor).
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';

export interface OrderCancellationSetting {
    /** The staff positions currently allowed to cancel an order. */
    positions: string[];
    /** The full catalogue of selectable positions. */
    available_positions: string[];
}

export function getOrderCancellationSetting(): Promise<{ data: OrderCancellationSetting }> {
    return apiGet<{ data: OrderCancellationSetting }>('/api/settings/order-cancellation');
}

export function updateOrderCancellationPositions(positions: string[]): Promise<{ data: OrderCancellationSetting }> {
    return apiPut<{ data: OrderCancellationSetting }>('/api/settings/order-cancellation', { positions } as unknown as JsonValue);
}

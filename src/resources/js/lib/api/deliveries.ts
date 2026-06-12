/**
 * Deliveries settlement API (P-G7) — mirror of
 * {@link \App\Http\Controllers\Pos\DeliveriesController}.
 *
 * Pending-verification delivery orders (punched with NO tender at the POS)
 * await the provider's statement here. Confirm = the statement matched the
 * expected payout; adjust = the provider paid a different amount and the
 * difference is stored as the reconciliation variance. Only confirmation
 * turns the order into revenue (dated at the confirmation moment).
 *
 * Money values are OMR decimal-3 strings. Never parseFloat for display.
 */

import { apiGet, apiPost } from '@/lib/api';

export type DeliveryStatusTab = 'pending' | 'confirmed';

export interface DeliveryOrder {
    id: number;
    uuid: string;
    branch_id: number;
    branch_name: string | null;
    receipt_number: string | null;
    provider_id: number | null;
    provider_name: string | null;
    reference: string | null;
    customer_phone: string | null;
    driver_phone: string | null;
    /** decimal-2 string, frozen at punch. */
    commission_percent: string;
    grand_total: string;
    expected_payout: string;
    received_amount: string | null;
    variance: string | null;
    punched_at: string | null;
    confirmed_at: string | null;
    status: string;
}

export interface DeliveriesPage {
    data: DeliveryOrder[];
    totals: {
        count: number;
        punched_total: string;
        expected_total: string;
        /** Confirmed tab only. */
        received_total?: string;
        variance_total?: string;
    };
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ConfirmResult {
    data: { orders_confirmed: number; commissions_recorded: number };
}

export function listDeliveries(status: DeliveryStatusTab = 'pending', page = 1): Promise<DeliveriesPage> {
    return apiGet<DeliveriesPage>(`/api/deliveries?status=${status}&page=${page}`);
}

/** Bulk: the statement matched — settle at the expected payout. */
export function confirmDeliveries(orderIds: number[]): Promise<ConfirmResult> {
    return apiPost<ConfirmResult>('/api/deliveries/confirm', { order_ids: orderIds });
}

/** Single order: settle at the amount actually received (variance recorded). */
export function adjustDelivery(uuid: string, receivedAmount: string): Promise<ConfirmResult> {
    return apiPost<ConfirmResult>(`/api/deliveries/${uuid}/adjust`, { received_amount: receivedAmount });
}

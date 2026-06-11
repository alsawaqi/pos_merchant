/**
 * Order Numbering policy API — company-level setting (P-F8).
 *
 * The merchant defines how POS order numbers look (prefix + zero-padded
 * counter, e.g. KLD-0042), whether each BRANCH has its own sequence or
 * the COMPANY shares one, and whether the counter restarts each day.
 * pos_api emits the policy in /device/config and allocates the actual
 * numbers atomically on POST /device/orders/next-number.
 *
 * Server gate: orders.cancel for BOTH read and write (403 otherwise) —
 * the same merchant-policy gate as the sibling POS settings.
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';

export interface OrderNumberingSetting {
    /** Master switch — disabled companies get plain unnumbered orders. */
    enabled: boolean;
    /** Receipt prefix, up to 8 chars; may be empty (e.g. "KLD-"). */
    prefix: string;
    /** Zero-pad width for the counter, 3..6 (42 + pad 4 → "0042"). */
    pad: number;
    /** 'branch' = each branch counts independently; 'company' = one shared sequence. */
    scope: 'branch' | 'company';
    /** Restart the counter every day (common for call-out numbers). */
    daily_reset: boolean;
}

export function getOrderNumberingSetting(): Promise<{ data: OrderNumberingSetting }> {
    return apiGet<{ data: OrderNumberingSetting }>('/api/settings/order-numbering');
}

export function updateOrderNumberingSetting(setting: OrderNumberingSetting): Promise<{ data: OrderNumberingSetting }> {
    return apiPut<{ data: OrderNumberingSetting }>('/api/settings/order-numbering', setting as unknown as JsonValue);
}

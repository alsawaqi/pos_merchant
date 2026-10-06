/**
 * LAUNCH packaging add-on — the merchant's per-order packaging.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\OrderPackagingController}:
 *   GET /api/inventory/order-packaging               the four lists (catalogue.view or inventory.view)
 *   PUT /api/inventory/order-packaging/{orderType}   replace one list ("Edit recipes")
 *
 * pos_api takes the list of the order's final type ONCE per whole order,
 * when it takes the order's stock.
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';
import type { OrderTypeBucket } from '@/lib/orderTypes';

export interface OrderPackagingLine {
    type: 'ingredient' | 'product';
    ingredient_uuid: string | null;
    product_uuid: string | null;
    name: string | null;
    name_ar: string | null;
    /** Base quantity (ingredient) or pieces (physical item). */
    quantity: string;
    /** The ingredient's base unit; null for a physical item. */
    unit: string | null;
    /** How it was typed while that still holds (a unit or a pack token), else null. */
    entered_unit: string | null;
    entered_quantity: string | null;
    pack_uuid: string | null;
    /** Fix order PK-B1 — false when pos_api would skip the item (deleted or inactive). */
    available?: boolean;
    /** Today's cost of the line (OMR), null while it has no cost. */
    cost: string | null;
}

/** Fix order PK-B1 (L4) — an item the list may hold, with its packs. */
export interface OrderPackagingItem {
    uuid: string;
    name: string;
    name_ar: string | null;
    kind: 'physical' | 'bought_in';
    cost_price: string | null;
    packs: { uuid: string; token: string; pieces: string; display_name: string; display_name_ar: string }[];
}

export interface OrderPackagingList {
    lines: OrderPackagingLine[];
    /** Today's cost of the list per order (OMR). */
    cost: string;
    cost_complete: boolean;
}

export interface OrderPackagingState {
    can_edit: boolean;
    lists: Record<OrderTypeBucket, OrderPackagingList>;
    /** The physical items and bought-in products the lists may hold (active, used with food). */
    items: OrderPackagingItem[];
}

export interface OrderPackagingLinePayload {
    type: 'ingredient' | 'product';
    ingredient_uuid?: string | null;
    product_uuid?: string | null;
    quantity: string | number;
    /** An ingredient's unit (base when empty) or a physical item's pack token (pieces when empty). */
    unit?: string | null;
}

export function getOrderPackaging(): Promise<{ data: OrderPackagingState }> {
    return apiGet<{ data: OrderPackagingState }>('/api/inventory/order-packaging');
}

export function saveOrderPackaging(orderType: OrderTypeBucket, lines: OrderPackagingLinePayload[]): Promise<{ data: OrderPackagingState }> {
    return apiPut<{ data: OrderPackagingState }>(`/api/inventory/order-packaging/${orderType}`, { lines } as unknown as JsonValue);
}

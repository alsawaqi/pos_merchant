/**
 * LAUNCH review add-on — "Can be removed" on a product's recipe lines.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ProductRemovableController}:
 *   GET /api/products/{uuid}/removable  (catalogue.view)
 *   PUT /api/products/{uuid}/removable  (catalogue.manage) { lines }
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';
import type { RemovableLinePayload, RemovableSavedLine } from '@/lib/menuExtras';

export interface RemovableLine {
    ingredient_uuid: string;
    ingredient_name: string | null;
    addon_uuid: string;
    /** What the customer sees: "NO Ketchup" / "بدون كاتشب". */
    name: string;
    name_ar: string | null;
    /** The label part ("Ketchup"). */
    label: string | null;
    label_ar: string | null;
    /** LAUNCH combo add-on — '0.000' (no change) or a minus price ('-0.100'). */
    price?: string;
}

export interface RemovableState {
    /** Only a made-to-order product keeps a removed ingredient in stock; for the others it only prints. */
    applies_to_stock: boolean;
    lines: RemovableLine[];
}

export function getRemovable(productUuid: string): Promise<{ data: RemovableState }> {
    return apiGet<{ data: RemovableState }>(`/api/products/${productUuid}/removable`);
}

/**
 * `expected` = the ticks the page loaded with (fix order C-1, M1): the server
 * answers 409 when they are no longer the saved ones, and writes nothing.
 */
export function saveRemovable(productUuid: string, lines: RemovableLinePayload[], expected: RemovableSavedLine[]): Promise<{ data: RemovableState }> {
    return apiPut<{ data: RemovableState }>(`/api/products/${productUuid}/removable`, { lines, expected } as unknown as JsonValue);
}

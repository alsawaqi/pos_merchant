/**
 * LAUNCH review add-on — "Can be removed" on a product's recipe lines.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ProductRemovableController}:
 *   GET /api/products/{uuid}/removable  (catalogue.view)
 *   PUT /api/products/{uuid}/removable  (catalogue.manage) { lines }
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';
import type { RemovableLinePayload } from '@/lib/menuExtras';

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
}

export interface RemovableState {
    /** Only a made-to-order product keeps a removed ingredient in stock; for the others it only prints. */
    applies_to_stock: boolean;
    lines: RemovableLine[];
}

export function getRemovable(productUuid: string): Promise<{ data: RemovableState }> {
    return apiGet<{ data: RemovableState }>(`/api/products/${productUuid}/removable`);
}

export function saveRemovable(productUuid: string, lines: RemovableLinePayload[]): Promise<{ data: RemovableState }> {
    return apiPut<{ data: RemovableState }>(`/api/products/${productUuid}/removable`, { lines } as unknown as JsonValue);
}

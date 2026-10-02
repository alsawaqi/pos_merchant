/**
 * LAUNCH-P3 P3-4 — prep items (sauce, dough): an ingredient with its own
 * recipe per batch and a yield, used by recipes like an ingredient and
 * exploded into its raw ingredients when a dish sells. No stock of its own.
 * Read: catalogue.view or inventory.view; writes: "Edit recipes".
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { RecipeHistory } from '@/lib/api/catalogue';

export type PrepUnit = 'g' | 'ml' | 'piece';

export interface PrepItemLine {
    ingredient: {
        id: number;
        uuid: string;
        name: string;
        name_ar: string | null;
        unit: string;
        is_prep: boolean;
        /** Cost per base unit (a prep component: derived). */
        default_unit_cost: string;
        piece_unit_label: string | null;
        deleted: boolean;
    } | null;
    /** Per batch, in the component's BASE unit. */
    quantity: string;
    entered_unit: string | null;
    entered_quantity: string | null;
    /** Cost of this line per batch (6 decimals). */
    line_cost: string;
}

export interface PrepItem {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    unit: PrepUnit;
    is_prep: true;
    /** What one batch makes, in the prep item's own unit. */
    prep_yield_quantity: string;
    status: string;
    /** Live cost per base unit (6 decimals) and per batch. */
    unit_cost: string;
    batch_cost: string;
    /** Prep levels: 1 = made of raw ingredients only (max 3). */
    depth: number;
    lines?: PrepItemLine[];
    used_by: { product_recipes: number; addon_lines: number; prep_recipes: number } | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PrepItemLinePayload {
    ingredient_uuid: string;
    quantity: string | number;
    /** '' / null = the component's base unit; '@piece' = its piece unit. */
    unit?: string | null;
}

export interface SavePrepItemPayload {
    name?: string;
    name_ar?: string | null;
    unit?: PrepUnit;
    prep_yield_quantity?: string | number;
    lines?: PrepItemLinePayload[];
    note?: string | null;
}

export function listPrepItems(): Promise<{ data: PrepItem[] }> {
    return apiGet<{ data: PrepItem[] }>('/api/prep-items');
}

export function getPrepItem(uuid: string): Promise<{ data: PrepItem }> {
    return apiGet<{ data: PrepItem }>(`/api/prep-items/${uuid}`);
}

export function createPrepItem(payload: SavePrepItemPayload): Promise<{ data: PrepItem }> {
    return apiPost<{ data: PrepItem }>('/api/prep-items', payload as unknown as JsonValue);
}

export function updatePrepItem(uuid: string, payload: SavePrepItemPayload): Promise<{ data: PrepItem }> {
    return apiPatch<{ data: PrepItem }>(`/api/prep-items/${uuid}`, payload as unknown as JsonValue);
}

export function deletePrepItem(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/prep-items/${uuid}`);
}

export function getPrepItemHistory(uuid: string): Promise<{ data: RecipeHistory }> {
    return apiGet<{ data: RecipeHistory }>(`/api/prep-items/${uuid}/history`);
}

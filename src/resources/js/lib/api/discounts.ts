/**
 * Discounts API — rules + targets + lifecycle.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\DiscountsController}.
 *
 * Permission gates server-side: discounts.view for GETs,
 * discounts.manage for every write.
 */

import { apiDelete, apiGet, apiPatch, apiPost, apiPut, type JsonValue } from '@/lib/api';

export type DiscountScope = 'product' | 'category' | 'order';
export type DiscountAmountType = 'percent' | 'fixed';
export type DiscountStatus = 'active' | 'paused' | 'expired';
export type DiscountTargetType = 'product' | 'category';

// ---- Domain types -----------------------------------------------

export interface DiscountTarget {
    id: number;
    target_type: DiscountTargetType;
    target_id: number;
}

export interface Discount {
    id: number;
    uuid: string;
    name: string;
    scope: DiscountScope;
    amount_type: DiscountAmountType;
    /** Decimal:3 string (percent: 0-100; fixed: OMR). Never parseFloat for money. */
    amount: string;
    validity_start: string | null;
    validity_end: string | null;
    /** Bitmask Sun=1..Sat=64. NULL = every day. */
    dayofweek_mask: number | null;
    /** HH:MM:SS or null. */
    time_start: string | null;
    time_end: string | null;
    /** NULL = all branches; array of ints = subset. */
    branch_scope_json: number[] | null;
    stackable: boolean;
    requires_manager_approval: boolean;
    /**
     * P-F4 — order-scope rules only: true = the device applies the rule
     * by itself to every qualifying order. Always true for product/
     * category scopes (server-forced; targeted rules stay automatic).
     */
    auto_apply: boolean;
    status: DiscountStatus;
    /** Computed by server: composes status + validity window. */
    currently_active: boolean;
    targets?: DiscountTarget[];
    targets_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

// ---- Payloads ---------------------------------------------------

export interface CreateDiscountPayload {
    name: string;
    scope: DiscountScope;
    amount_type: DiscountAmountType;
    amount: string;
    validity_start?: string | null;
    validity_end?: string | null;
    dayofweek_mask?: number | null;
    time_start?: string | null;
    time_end?: string | null;
    branch_scope_json?: number[] | null;
    stackable?: boolean;
    requires_manager_approval?: boolean;
    /** Order scope only; the server forces true for product/category. */
    auto_apply?: boolean;
}

export type UpdateDiscountPayload = Partial<CreateDiscountPayload> & {
    status?: DiscountStatus;
};

export interface SetTargetsPayload {
    targets: { target_type: DiscountTargetType; target_id: number }[];
}

// ---- Endpoints --------------------------------------------------

export function listDiscounts(): Promise<{ data: Discount[] }> {
    return apiGet<{ data: Discount[] }>('/api/discounts');
}

export function getDiscount(uuid: string): Promise<{ data: Discount }> {
    return apiGet<{ data: Discount }>(`/api/discounts/${uuid}`);
}

export function createDiscount(payload: CreateDiscountPayload): Promise<{ data: Discount }> {
    return apiPost<{ data: Discount }>('/api/discounts', payload as unknown as JsonValue);
}

export function updateDiscount(uuid: string, payload: UpdateDiscountPayload): Promise<{ data: Discount }> {
    return apiPatch<{ data: Discount }>(`/api/discounts/${uuid}`, payload as unknown as JsonValue);
}

export function deleteDiscount(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/discounts/${uuid}`);
}

export function pauseDiscount(uuid: string): Promise<{ data: Discount }> {
    return apiPost<{ data: Discount }>(`/api/discounts/${uuid}/pause`);
}

export function resumeDiscount(uuid: string): Promise<{ data: Discount }> {
    return apiPost<{ data: Discount }>(`/api/discounts/${uuid}/resume`);
}

export function syncDiscountTargets(uuid: string, payload: SetTargetsPayload): Promise<{ data: Discount }> {
    return apiPut<{ data: Discount }>(`/api/discounts/${uuid}/targets`, payload as unknown as JsonValue);
}

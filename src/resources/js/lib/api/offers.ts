/**
 * Offers API — P-F9 merchant promotions.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\OffersController}. Each offer
 * is a `type` + type-specific `config` (the canonical device shape; money
 * inside config is integer BAISAS). Shared applicability axes mirror
 * discounts (validity / weekday mask / time window / branch scope).
 *
 * Permission gates server-side: discounts.view for GETs,
 * discounts.manage for every write (offers share the discounts keys).
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type OfferType = 'bogo' | 'bundle' | 'multi_buy' | 'cheapest_free' | 'spend_get';
export type OfferStatus = 'active' | 'paused';
export type SpendGetRewardType = 'percent_off' | 'fixed_off' | 'free_product';

// ---- Per-type config shapes (canonical — the device engine contract) ----

export interface BogoConfig {
    buy: { product_ids: number[]; category_ids: number[]; qty: number };
    get: {
        same_as_buy: boolean;
        product_ids: number[];
        category_ids: number[];
        qty: number;
        /** 1..100; 100 = free. */
        percent_off: number;
    };
}

export interface BundleConfig {
    /** Integer baisas. */
    price_baisas: number;
    groups: { label: string; label_ar: string | null; product_ids: number[]; qty: number }[];
}

export interface MultiBuyConfig {
    product_ids: number[];
    category_ids: number[];
    /** ≥ 2. */
    qty: number;
    /** Integer baisas. */
    price_baisas: number;
}

export interface CheapestFreeConfig {
    product_ids: number[];
    category_ids: number[];
    /** ≥ 2. */
    qty: number;
    /** ≥ 1 and < qty. */
    free_count: number;
}

export interface SpendGetConfig {
    /** Integer baisas. */
    min_subtotal_baisas: number;
    reward_type: SpendGetRewardType;
    /** percent 1..100 | baisas ≥ 1 | null for free_product. */
    reward_value: number | null;
    /** Required for free_product; null otherwise. */
    reward_product_id: number | null;
}

export type OfferConfig = BogoConfig | BundleConfig | MultiBuyConfig | CheapestFreeConfig | SpendGetConfig;

// ---- Domain types -----------------------------------------------

export interface Offer {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    type: OfferType;
    config: OfferConfig;
    /**
     * true = the device applies the offer by itself to every qualifying
     * order; false = the cashier picks it. ALWAYS false for bundle
     * (server-forced — bundles are composed by the cashier).
     */
    auto_apply: boolean;
    validity_start: string | null;
    validity_end: string | null;
    /** Bitmask Sun=1..Sat=64. NULL = every day. */
    dayofweek_mask: number | null;
    /** HH:MM:SS or null. */
    time_start: string | null;
    time_end: string | null;
    /** NULL = all branches; array of ints = subset. */
    branch_scope_json: number[] | null;
    /** How many times one order may apply this offer. NULL = unlimited. */
    max_per_order: number | null;
    status: OfferStatus;
    /** Computed by server: composes status + validity window. */
    currently_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

// ---- Payloads ---------------------------------------------------

export interface CreateOfferPayload {
    name: string;
    name_ar?: string | null;
    type: OfferType;
    config: OfferConfig;
    /** Ignored for bundle (server forces false). */
    auto_apply?: boolean;
    validity_start?: string | null;
    validity_end?: string | null;
    dayofweek_mask?: number | null;
    time_start?: string | null;
    time_end?: string | null;
    branch_scope_json?: number[] | null;
    max_per_order?: number | null;
}

export type UpdateOfferPayload = Partial<CreateOfferPayload> & {
    status?: OfferStatus;
};

// ---- Endpoints --------------------------------------------------

export function listOffers(): Promise<{ data: Offer[] }> {
    return apiGet<{ data: Offer[] }>('/api/offers');
}

export function getOffer(uuid: string): Promise<{ data: Offer }> {
    return apiGet<{ data: Offer }>(`/api/offers/${uuid}`);
}

export function createOffer(payload: CreateOfferPayload): Promise<{ data: Offer }> {
    return apiPost<{ data: Offer }>('/api/offers', payload as unknown as JsonValue);
}

export function updateOffer(uuid: string, payload: UpdateOfferPayload): Promise<{ data: Offer }> {
    return apiPatch<{ data: Offer }>(`/api/offers/${uuid}`, payload as unknown as JsonValue);
}

export function deleteOffer(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/offers/${uuid}`);
}

export function pauseOffer(uuid: string): Promise<{ data: Offer }> {
    return apiPost<{ data: Offer }>(`/api/offers/${uuid}/pause`);
}

export function resumeOffer(uuid: string): Promise<{ data: Offer }> {
    return apiPost<{ data: Offer }>(`/api/offers/${uuid}/resume`);
}

/**
 * Delivery Providers API — CRUD + per-product price overrides.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\DeliveryProvidersController}.
 *
 * Permission gates server-side: catalogue.view for read, catalogue.manage
 * for every write.
 */

import { apiDelete, apiGet, apiPatch, apiPost, apiPut, type JsonValue } from '@/lib/api';

// ---- Domain types -----------------------------------------------

export interface DeliveryProvider {
    id: number;
    uuid: string;
    name: string;
    /** 7-char hex (#RRGGBB) for the POS chip color; null when unset. */
    color: string | null;
    is_active: boolean;
    sort_order: number;
    /** Present when controller did withCount('prices'). */
    prices_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface ProductDeliveryPrice {
    id: number;
    product_id: number;
    delivery_provider_id: number;
    /** OMR decimal:3 string. Never parseFloat. */
    price: string;
    created_at: string | null;
    updated_at: string | null;
    /** Inlined when the controller eager-loaded the provider. */
    delivery_provider?: {
        id: number;
        uuid: string;
        name: string;
        color: string | null;
    } | null;
}

// ---- Payloads ---------------------------------------------------

export interface CreateDeliveryProviderPayload {
    name: string;
    color?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface UpdateDeliveryProviderPayload {
    name?: string;
    color?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface SetDeliveryPricePayload {
    /** OMR string, must be > 0. */
    price: string;
}

// ---- Provider CRUD ---------------------------------------------

export function listDeliveryProviders(): Promise<{ data: DeliveryProvider[] }> {
    return apiGet<{ data: DeliveryProvider[] }>('/api/delivery-providers');
}

export function createDeliveryProvider(
    payload: CreateDeliveryProviderPayload,
): Promise<{ data: DeliveryProvider }> {
    return apiPost<{ data: DeliveryProvider }>(
        '/api/delivery-providers',
        payload as unknown as JsonValue,
    );
}

export function updateDeliveryProvider(
    uuid: string,
    payload: UpdateDeliveryProviderPayload,
): Promise<{ data: DeliveryProvider }> {
    return apiPatch<{ data: DeliveryProvider }>(
        `/api/delivery-providers/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteDeliveryProvider(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/delivery-providers/${uuid}`);
}

// ---- Per-product prices ----------------------------------------

export function listProductDeliveryPrices(productUuid: string): Promise<{ data: ProductDeliveryPrice[] }> {
    return apiGet<{ data: ProductDeliveryPrice[] }>(`/api/products/${productUuid}/delivery-prices`);
}

export function setProductDeliveryPrice(
    productUuid: string,
    providerUuid: string,
    payload: SetDeliveryPricePayload,
): Promise<{ data: ProductDeliveryPrice }> {
    return apiPut<{ data: ProductDeliveryPrice }>(
        `/api/products/${productUuid}/delivery-prices/${providerUuid}`,
        payload as unknown as JsonValue,
    );
}

export function removeProductDeliveryPrice(
    productUuid: string,
    providerUuid: string,
): Promise<void> {
    return apiDelete<void>(`/api/products/${productUuid}/delivery-prices/${providerUuid}`);
}

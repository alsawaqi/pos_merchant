/**
 * Taxes API — company-level tax CRUD.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\TaxesController}.
 *
 * Permission gates server-side: catalogue.view for read, catalogue.manage for
 * every write. The Main POS fetches the active set via /device/config and adds
 * each, as its own line, on top of the order total (exclusive).
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

// ---- Domain types -----------------------------------------------

export interface Tax {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    /** Percentage as a decimal:2 string, e.g. "5.00". Never parseFloat for money. */
    rate_percent: string;
    is_active: boolean;
    sort_order: number;
    created_at: string | null;
    updated_at: string | null;
}

// ---- Payloads ---------------------------------------------------

export interface CreateTaxPayload {
    name: string;
    name_ar?: string | null;
    rate_percent: number | string;
    is_active?: boolean;
    sort_order?: number;
}

export interface UpdateTaxPayload {
    name?: string;
    name_ar?: string | null;
    rate_percent?: number | string;
    is_active?: boolean;
    sort_order?: number;
}

// ---- CRUD -------------------------------------------------------

export function listTaxes(): Promise<{ data: Tax[] }> {
    return apiGet<{ data: Tax[] }>('/api/taxes');
}

export function createTax(payload: CreateTaxPayload): Promise<{ data: Tax }> {
    return apiPost<{ data: Tax }>('/api/taxes', payload as unknown as JsonValue);
}

export function updateTax(uuid: string, payload: UpdateTaxPayload): Promise<{ data: Tax }> {
    return apiPatch<{ data: Tax }>(`/api/taxes/${uuid}`, payload as unknown as JsonValue);
}

export function deleteTax(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/taxes/${uuid}`);
}

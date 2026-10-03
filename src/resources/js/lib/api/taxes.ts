/**
 * Taxes API — company-level tax CRUD.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\TaxesController}.
 *
 * Permission gates server-side: catalogue.view for read, catalogue.manage for
 * every write. For a VAT-registered company the POS charges the active set,
 * one line per tax: taken out of the price when menu prices include VAT, else
 * added on top. Delivery-app orders carry no tax; an unregistered company
 * charges none.
 */

import { apiDelete, apiGet, apiPatch, apiPost, apiPut, type JsonValue } from '@/lib/api';

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

// ---- LAUNCH-P4 B1 — VAT settings ----------------------------------

/**
 * The VAT registration (read-only: the company record pos_admin keeps) plus
 * the merchant's "menu prices include VAT" switch (default true).
 */
export interface TaxSettings {
    vat_registered: boolean;
    vat_registered_at: string | null;
    vat_number: string | null;
    prices_include_vat: boolean;
    has_active_tax: boolean;
    /** Registered but no active tax row: nothing is charged. */
    needs_vat_row: boolean;
}

export function getTaxSettings(): Promise<{ data: TaxSettings }> {
    return apiGet<{ data: TaxSettings }>('/api/settings/tax');
}

export function updatePricesIncludeVat(value: boolean): Promise<{ data: TaxSettings }> {
    return apiPut<{ data: TaxSettings }>(
        '/api/settings/tax/prices-include-vat',
        { prices_include_vat: value } as unknown as JsonValue,
    );
}

/** One click: "VAT / ضريبة القيمة المضافة" at 5% (registered companies only). */
export function addStandardVat(): Promise<{ data: TaxSettings & { tax: Tax } }> {
    return apiPost<{ data: TaxSettings & { tax: Tax } }>('/api/settings/tax/add-vat');
}

// ---- PT — purchase-tax-recoverable company setting ---------------

export function getPurchaseTaxRecoverable(): Promise<{ data: { purchase_tax_recoverable: boolean } }> {
    return apiGet<{ data: { purchase_tax_recoverable: boolean } }>('/api/settings/purchase-tax-recoverable');
}

export function updatePurchaseTaxRecoverable(value: boolean): Promise<{ data: { purchase_tax_recoverable: boolean } }> {
    return apiPut<{ data: { purchase_tax_recoverable: boolean } }>(
        '/api/settings/purchase-tax-recoverable',
        { purchase_tax_recoverable: value } as unknown as JsonValue,
    );
}

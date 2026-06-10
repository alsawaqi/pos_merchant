/**
 * Customers API — customer book + vehicle plates.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\CustomersController}.
 * Permission gates server-side: customers.view for read endpoints,
 * customers.manage for everything else.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export interface CustomerVehiclePlate {
    id: number;
    uuid: string;
    customer_id: number;
    /** Canonical form: trim + uppercase. Server stores + returns the same. */
    plate_number: string;
    created_at: string | null;
}

export interface Customer {
    id: number;
    uuid: string;
    name: string;
    phone: string;
    /** decimal:3 OMR string (opaque, never parseFloat). Store credit
     *  (wallet) — separate from loyalty. Loyalty points/stamps live
     *  per-rule; fetch via getCustomerLoyalty(). */
    wallet_balance: string;
    /** Phase D3 — optional Y-m-d date (timezone-naive). */
    date_of_birth: string | null;
    /** Phase D3 — free-form tag strings (VIP, Blocked…); [] = none. */
    tags: string[];
    /** Phase D3 — server-derived: birthday within the next 30 days. */
    upcoming_birthday: boolean;
    created_at: string | null;
    updated_at: string | null;
    /** Always populated on the show endpoint + the list endpoint. */
    vehicle_plates?: CustomerVehiclePlate[];
    /** Convenience count surfaced for the list page chip. */
    vehicle_plates_count?: number;
}

export interface PaginatedCustomers {
    data: Customer[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface CreateCustomerPayload {
    name: string;
    phone: string;
    /** Y-m-d, never in the future. */
    date_of_birth?: string | null;
    /** Trimmed server-side; case-insensitive dupes rejected. */
    tags?: string[];
    /** Optional initial plates attached in the same transaction. */
    plates?: string[];
}

export interface UpdateCustomerPayload {
    name?: string;
    phone?: string;
    /** Explicit null CLEARS the stored date. */
    date_of_birth?: string | null;
    /** Replaces the whole tag set; [] clears it. */
    tags?: string[];
}

export interface AttachPlatePayload {
    plate_number: string;
}

export interface ListCustomersParams {
    /** Case-insensitive LIKE across name + phone + plate. */
    search?: string;
    /** Exact stored tag (from listCustomerTags) narrows the list. */
    tag?: string;
    per_page?: number;
    page?: number;
}

// ---- Endpoints --------------------------------------------------

export function listCustomers(params: ListCustomersParams = {}): Promise<PaginatedCustomers> {
    return apiGet<PaginatedCustomers>('/api/customers', {
        query: {
            search: params.search,
            tag: params.tag,
            per_page: params.per_page,
            page: params.page,
        },
    });
}

/** Phase D3 — the company's distinct customer tags (filter dropdown). */
export function listCustomerTags(): Promise<{ data: string[] }> {
    return apiGet<{ data: string[] }>('/api/customers/tags');
}

export function getCustomer(uuid: string): Promise<{ data: Customer }> {
    return apiGet<{ data: Customer }>(`/api/customers/${uuid}`);
}

export function createCustomer(
    payload: CreateCustomerPayload,
): Promise<{ data: Customer }> {
    return apiPost<{ data: Customer }>(
        '/api/customers',
        payload as unknown as JsonValue,
    );
}

export function updateCustomer(
    uuid: string,
    payload: UpdateCustomerPayload,
): Promise<{ data: Customer }> {
    return apiPatch<{ data: Customer }>(
        `/api/customers/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteCustomer(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/customers/${uuid}`);
}

export function attachVehiclePlate(
    customerUuid: string,
    payload: AttachPlatePayload,
): Promise<{ data: CustomerVehiclePlate }> {
    return apiPost<{ data: CustomerVehiclePlate }>(
        `/api/customers/${customerUuid}/plates`,
        payload as unknown as JsonValue,
    );
}

export function detachVehiclePlate(plateUuid: string): Promise<void> {
    return apiDelete<void>(`/api/customer-plates/${plateUuid}`);
}

// ---- Customer 360 (v2 #8) ---------------------------------------

export interface CustomerAnalytics {
    rollups: {
        order_count: number;
        /** decimal:3 OMR string. */
        total_spend: string;
        avg_ticket: string;
        first_order_at: string | null;
        last_order_at: string | null;
    };
    favorite_item: {
        product_id: number | null;
        product_name: string;
        total_qty: string;
        total_revenue: string;
        line_count: number;
    } | null;
    /** Trailing-12-month paid gross + count, zero-filled. */
    spend_trend: { month: string; gross: string; count: number }[];
}

export interface CustomerOrderRow {
    id: number;
    uuid: string;
    branch_name: string | null;
    order_type: string | null;
    status: string | null;
    items_count: number;
    discount_total: string;
    grand_total: string;
    opened_at: string | null;
}

export interface CustomerOrdersPayload {
    totals: { count: number; paid_total: string };
    rows: CustomerOrderRow[];
    meta: { current_page: number; per_page: number; last_page: number; total: number };
}

export function getCustomerAnalytics(uuid: string): Promise<{ data: CustomerAnalytics }> {
    return apiGet<{ data: CustomerAnalytics }>(`/api/customers/${uuid}/analytics`);
}

export function getCustomerOrders(
    uuid: string,
    page = 1,
    perPage = 20,
): Promise<{ data: CustomerOrdersPayload }> {
    return apiGet<{ data: CustomerOrdersPayload }>(`/api/customers/${uuid}/orders`, {
        query: { page, per_page: perPage },
    });
}

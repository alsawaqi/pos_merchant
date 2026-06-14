/**
 * Branches API — TWO surfaces:
 *
 *   listBranches()           → lean shape from /api/branches.
 *                              Used by the Portal Users branch-
 *                              scope multi-select. No permission
 *                              gate server-side, every authed
 *                              merchant user can call it.
 *
 *   listMerchantBranches()   → full shape from /api/pos/branches.
 *   showMerchantBranch(uuid)   Gated by branches.view server-side.
 *   updateMerchantBranch(...)  branches.update gates the PATCH;
 *                              status transitions additionally
 *                              require branches.transition_status
 *                              (enforced inside the action layer,
 *                              surfaces as 403 with a message).
 */

import { apiGet, apiPatch, apiPut, type JsonValue } from '@/lib/api';

export type BranchStatus = 'active' | 'inactive';
export type BranchOrderType =
    | 'quick'
    | 'dine_in'
    | 'to_go'
    | 'delivery'
    | 'car';

/** Lean shape returned by /api/branches (Portal Users picker). */
export interface Branch {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    code: string | null;
    status: BranchStatus | null;
}

/** Full shape returned by /api/pos/branches[/:uuid]. */
export interface MerchantBranch {
    id: number;
    uuid: string;
    code: string | null;
    name: string;
    name_ar: string | null;
    manager_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    country_id: number | null;
    region_id: number | null;
    district_id: number | null;
    city_id: number | null;
    latitude: string | null;
    longitude: string | null;
    geofence_radius_m: number | null;
    /**
     * Map of weekday key → schedule. Day keys typically
     * `mon|tue|wed|thu|fri|sat|sun`. Each value: {open, close, closed}.
     */
    opening_hours_json: Record<string, OpeningDay> | null;
    default_order_type: BranchOrderType | null;
    status: BranchStatus | null;
    /** Custom POS receipt template; null until the merchant authors one. */
    receipt_template: ReceiptTemplate | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface OpeningDay {
    open?: string;
    close?: string;
    closed?: boolean;
}

/**
 * Per-branch custom receipt template — what the POS device prints.
 * All fields optional; an all-empty template = device built-in default.
 */
export interface ReceiptTemplate {
    business_name: string | null;
    business_name_ar: string | null;
    cr_number: string | null;
    vat_number: string | null;
    address: string | null;
    phone: string | null;
    header_lines: string[];
    footer_lines: string[];
    show_qr: boolean;
    /** Base64-encoded PNG (no data: prefix); browser-resized to a receipt logo. */
    logo_base64: string | null;
}

export interface UpdateMerchantBranchPayload {
    name?: string;
    name_ar?: string | null;
    manager_name?: string | null;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
    // Location (latitude/longitude/geofence_radius_m) is admin-owned and
    // intentionally not part of the merchant update payload.
    opening_hours_json?: Record<string, OpeningDay> | null;
    default_order_type?: BranchOrderType;
    status?: BranchStatus;
}

export function listBranches(): Promise<{ data: Branch[] }> {
    return apiGet<{ data: Branch[] }>('/api/branches');
}

export function listMerchantBranches(): Promise<{ data: MerchantBranch[] }> {
    return apiGet<{ data: MerchantBranch[] }>('/api/pos/branches');
}

export function showMerchantBranch(
    uuid: string,
): Promise<{ data: MerchantBranch }> {
    return apiGet<{ data: MerchantBranch }>(`/api/pos/branches/${uuid}`);
}

export function updateMerchantBranch(
    uuid: string,
    payload: UpdateMerchantBranchPayload,
): Promise<{ data: MerchantBranch }> {
    return apiPatch<{ data: MerchantBranch }>(
        `/api/pos/branches/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function updateBranchReceiptTemplate(
    uuid: string,
    payload: ReceiptTemplate,
): Promise<{ data: MerchantBranch }> {
    return apiPut<{ data: MerchantBranch }>(
        `/api/pos/branches/${uuid}/receipt-template`,
        payload as unknown as JsonValue,
    );
}

// ---- Read-only: devices assigned to a branch (admin-managed) ----

export interface BranchDevice {
    id: number;
    uuid: string;
    name: string | null;
    serial_number: string | null;
    kiosk_id: string | null;
    device_type: string | null;
    status: string | null;
    assigned_at: string | null;
    last_seen_at: string | null;
}

export function listBranchDevices(uuid: string): Promise<{ data: BranchDevice[] }> {
    return apiGet<{ data: BranchDevice[] }>(`/api/pos/branches/${uuid}/devices`);
}

// ---- Branch detail (v2 #11): products + staff + activity ----

export interface BranchProductRow {
    product_id: number;
    uuid: string;
    name: string;
    /** decimal:3 OMR string. */
    base_price: string;
    /** 'unit' | 'ingredient' | 'untracked'. */
    stock_mode: string;
    is_available: boolean;
    /** decimal:3 string, or null when not unit-tracked at this branch. */
    stock_qty: string | null;
}

export interface BranchStaffMember {
    id: number;
    uuid: string;
    name: string;
    phone: string | null;
    staff_code: string | null;
    position: string | null;
    status: string | null;
    hired_at: string | null;
    last_login_at: string | null;
}

export interface BranchActivity {
    sales: {
        today: { gross: string; count: number };
        mtd: { gross: string; count: number };
    };
    hour_weekday: {
        window_days: number;
        cells: { weekday: number; hour: number; gross: string; count: number }[];
    };
    /** Trailing window (days) the three analytics blocks below cover. */
    window_days: number;
    /** Most-sold products at the branch (by qty), for the top-products donut. */
    top_products: { product_name: string; qty_sold: string; revenue: string }[];
    /** Most-active staff: paid orders + revenue, for the staff donut + list. */
    staff_activity: { staff_name: string; orders_paid: number; revenue: string }[];
    /** Zero-filled daily paid-gross series, for the trend line. */
    sales_trend: { date: string; gross: string; count: number }[];
    recent_orders: {
        uuid: string;
        status: string | null;
        order_type: string | null;
        grand_total: string;
        opened_at: string | null;
        staff_name: string | null;
        customer_name: string | null;
    }[];
    recent_shifts: {
        uuid: string;
        status: string | null;
        opened_at: string | null;
        closed_at: string | null;
        variance: string | null;
        staff_name: string | null;
    }[];
    recent_movements: {
        movement_type: string | null;
        quantity: string;
        occurred_at: string | null;
        ingredient_name: string | null;
        unit: string | null;
        recorded_by: string | null;
    }[];
}

export function getBranchProducts(uuid: string): Promise<{ data: BranchProductRow[] }> {
    return apiGet<{ data: BranchProductRow[] }>(`/api/pos/branches/${uuid}/products`);
}

export function getBranchStaff(uuid: string): Promise<{ data: BranchStaffMember[] }> {
    return apiGet<{ data: BranchStaffMember[] }>(`/api/pos/branches/${uuid}/staff`);
}

export function getBranchActivity(uuid: string): Promise<{ data: BranchActivity }> {
    return apiGet<{ data: BranchActivity }>(`/api/pos/branches/${uuid}/activity`);
}

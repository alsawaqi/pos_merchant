/**
 * Dine-in Table Insights API (v2) — the per-table record.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\TableInsightsController}.
 *
 *   GET /api/table-insights?branch_id=&date_from=&date_to=  → overview
 *   GET /api/table-insights/{uuid}?date_from=&date_to=      → one table
 *
 * Money values are decimal-3 OMR strings (parse before charting);
 * durations are whole seconds. Both endpoints are reports.view gated.
 */

import { apiGet } from '@/lib/api';

export interface TableInsightsRange {
    date_from?: string; // ISO date (YYYY-MM-DD)
    date_to?: string;   // ISO date (YYYY-MM-DD)
}

// ---- Overview (every table of a branch) -------------------------

export interface TableOverviewRow {
    id: number;
    uuid: string;
    label: string;
    floor_id: number;
    floor_name: string | null;
    seats: number;
    shape: string | null;
    status: string | null;
    sittings: number;
    revenue: string;
    avg_spend: string;
    avg_duration_seconds: number;
    total_duration_seconds: number;
    unique_customers: number;
    last_used_at: string | null;
    /** An open / held / kitchen order sits on this table right now. */
    active_now: boolean;
}

export interface TableOverviewPayload {
    branch: { id: number; uuid: string; name: string };
    window: { from: string; to: string };
    totals: {
        table_count: number;
        sittings: number;
        revenue: string;
        avg_spend: string;
        avg_duration_seconds: number;
        occupied_now: number;
    };
    tables: TableOverviewRow[];
}

export function fetchTablesOverview(
    branchId: number,
    range?: TableInsightsRange,
): Promise<{ data: TableOverviewPayload }> {
    const q = new URLSearchParams();
    q.set('branch_id', String(branchId));
    if (range?.date_from) q.set('date_from', range.date_from);
    if (range?.date_to) q.set('date_to', range.date_to);
    return apiGet<{ data: TableOverviewPayload }>(`/api/table-insights?${q.toString()}`);
}

// ---- Detail (one table's full record) ---------------------------

export interface TableSitting {
    order_uuid: string;
    receipt_number: string | null;
    opened_at: string | null;
    closed_at: string | null;
    duration_seconds: number | null;
    grand_total: string;
    items_count: number;
    customer_name: string | null;
    customer_phone: string | null;
    staff_name: string | null;
}

export interface TableTopCustomer {
    customer_id: number;
    name: string;
    phone: string | null;
    visits: number;
    spend: string;
}

export interface TableDetailPayload {
    table: {
        id: number;
        uuid: string;
        label: string;
        seats: number;
        min_party: number | null;
        max_party: number | null;
        shape: string | null;
        status: string | null;
        floor_id: number;
        floor_name: string | null;
        branch_id: number | null;
        branch_name: string | null;
    };
    window: { from: string; to: string };
    summary: {
        sittings: number;
        revenue: string;
        avg_spend: string;
        avg_duration_seconds: number;
        total_duration_seconds: number;
        unique_customers: number;
        last_used_at: string | null;
        first_used_at: string | null;
        busiest_hour: number | null;
        busiest_weekday: number | null;
        active_now: boolean;
    };
    sittings: TableSitting[];
    top_customers: TableTopCustomer[];
    revenue_trend: { date: string; gross: string; count: number }[];
    by_hour: { hour: number; count: number; gross: string }[];
    by_weekday: { weekday: number; count: number; gross: string }[];
}

export function fetchTableDetail(
    uuid: string,
    range?: TableInsightsRange,
): Promise<{ data: TableDetailPayload }> {
    const q = new URLSearchParams();
    if (range?.date_from) q.set('date_from', range.date_from);
    if (range?.date_to) q.set('date_to', range.date_to);
    const qs = q.toString();
    return apiGet<{ data: TableDetailPayload }>(`/api/table-insights/${uuid}${qs ? `?${qs}` : ''}`);
}

/**
 * Dashboard summary API — Phase 7b-7.
 *
 * One GET that the landing page hits on mount.
 */

import { apiGet } from '@/lib/api';

export interface DashboardSnapshot {
    gross: string;
    order_count: number;
}

export interface TopProductToday {
    product_id: number | null;
    product_name: string;
    revenue: string;
}

export interface DashboardAuditEvent {
    id: number;
    event: string;
    actor_name: string | null;
    created_at: string | null;
}

export interface SalesTrendPoint {
    date: string;     // YYYY-MM-DD
    gross: string;    // decimal-3 OMR
    count: number;
}

export interface PaymentMixSlice {
    method: string;
    /** Decimal-3 OMR. */
    amount: string;
    count: number;
}

export interface DashboardSummaryPayload {
    today: DashboardSnapshot;
    yesterday: DashboardSnapshot;
    mtd: DashboardSnapshot;
    top_product_today: TopProductToday | null;
    low_stock_count: number;
    recent_audit_events: DashboardAuditEvent[];
    // v2 graphs (§5.2): trailing-14-day trend + MTD top-N breakdowns.
    sales_trend: SalesTrendPoint[];
    top_products: { product_name: string; revenue: string }[];
    top_branches: { branch_name: string; gross: string }[];
    top_customers: { customer_name: string; total_spend: string }[];
    top_staff: { staff_name: string; revenue: string }[];
    top_ingredients: { ingredient_name: string; unit: string; consumed: string }[];
    // Sales-by-hour (day-of-week × hour) heatmap over the trailing window.
    hour_weekday: {
        window_days: number;
        cells: { weekday: number; hour: number; gross: string; count: number }[];
    };
    // MTD sales split by order type (channel) — for the channel-mix chart.
    order_type_mix: { order_type: string; gross: string; count: number }[];
    // §5.2 tiles: today's tender split, charity round-up, device fleet.
    payment_mix_today: PaymentMixSlice[];
    roundup_today: { total: string; count: number };
    /** Online = heartbeat within the last 5 minutes. */
    active_devices: { online: number; total: number };
}

export function fetchDashboardSummary(): Promise<{ data: DashboardSummaryPayload }> {
    return apiGet<{ data: DashboardSummaryPayload }>('/api/dashboard/summary');
}

// ---- Period-over-period sales comparison (dashboard + branch) ----

export interface SalesComparisonPoint {
    i: number;
    date: string;  // YYYY-MM-DD
    gross: string; // decimal-3 OMR
}

export interface SalesComparisonSide {
    from: string;
    to: string;
    /** Comparable to-date total (drives change_pct). */
    total: string;
    /** Whole-period total (previous side only). */
    full_total?: string;
    series: SalesComparisonPoint[];
}

export interface SalesComparisonPayload {
    period: 'week' | 'month';
    offset: number;
    in_progress: boolean;
    change_pct: number | null;
    current: SalesComparisonSide;
    previous: SalesComparisonSide;
}

export function fetchSalesComparison(params: {
    period: 'week' | 'month';
    offset?: number;
    branchId?: number;
}): Promise<{ data: SalesComparisonPayload }> {
    const q = new URLSearchParams();
    q.set('period', params.period);
    if (params.offset) q.set('offset', String(params.offset));
    if (params.branchId != null) q.set('branch_id', String(params.branchId));
    return apiGet<{ data: SalesComparisonPayload }>(`/api/dashboard/sales-comparison?${q.toString()}`);
}

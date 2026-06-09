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
}

export function fetchDashboardSummary(): Promise<{ data: DashboardSummaryPayload }> {
    return apiGet<{ data: DashboardSummaryPayload }>('/api/dashboard/summary');
}

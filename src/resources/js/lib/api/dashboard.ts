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

export interface DashboardSummaryPayload {
    today: DashboardSnapshot;
    yesterday: DashboardSnapshot;
    mtd: DashboardSnapshot;
    top_product_today: TopProductToday | null;
    low_stock_count: number;
    recent_audit_events: DashboardAuditEvent[];
}

export function fetchDashboardSummary(): Promise<{ data: DashboardSummaryPayload }> {
    return apiGet<{ data: DashboardSummaryPayload }>('/api/dashboard/summary');
}

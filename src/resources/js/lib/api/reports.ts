/**
 * Reports + Audit Log API — Phase 7b.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ReportsController}.
 *
 * 10 reports + audit log viewer. Every report takes a ReportFilter
 * shape (date_from + date_to + optional branch_ids + consolidated)
 * and returns a `{ data: ... }` envelope with report-specific keys.
 *
 * The TypeScript shapes here are CONSUMERS' contract -- if the
 * server adds a field, add it here too. If a field is stubbed
 * server-side (the _phase notes), the type marks it optional so
 * the Phase 7b-6 UI can render either way.
 */

import { apiGet } from '@/lib/api';

// ============================================================
// Shared filter shape
// ============================================================

export interface ReportFilter {
    date_from: string;             // ISO date (YYYY-MM-DD)
    date_to: string;               // ISO date (YYYY-MM-DD)
    branch_ids?: number[] | null;  // NULL/missing = all branches
    consolidated?: boolean;        // Default true
}

/**
 * Build the query object expected by apiGet's `query` option.
 * Drops null/empty filters so the URL stays clean.
 */
function buildQuery(filter: ReportFilter): Record<string, string | number | boolean | null | undefined> {
    const q: Record<string, string | number | boolean | null | undefined> = {
        date_from: filter.date_from,
        date_to: filter.date_to,
    };
    if (filter.consolidated !== undefined) {
        q.consolidated = filter.consolidated;
    }
    return q;
}

/**
 * branch_ids needs to be serialized as `branch_ids[]=1&branch_ids[]=2`,
 * which the standard query helper can't do. We build that segment
 * manually and append.
 */
function withBranchScope(url: string, branchIds: number[] | null | undefined): string {
    if (!branchIds || branchIds.length === 0) return url;
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}${branchIds.map((id) => `branch_ids[]=${id}`).join('&')}`;
}

function reportPath(key: string, filter: ReportFilter): string {
    const base = `/api/reports/${key}`;
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries(buildQuery(filter))) {
        if (v === undefined || v === null) continue;
        q.set(k, String(v));
    }
    return withBranchScope(`${base}?${q.toString()}`, filter.branch_ids);
}

// ============================================================
// Sales Report (§5.11.1)
// ============================================================

export interface SalesReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: {
        gross_sales: string;
        discount_total: string;
        net_sales: string;
        tax_total: string;
        refunds_total: string;
        cogs: string;
        gross_profit: string;
        operating_expenses: string;
        net_profit: string;
        order_count: number;
        refund_count: number;
        avg_ticket: string;
    };
    by_hour?: { hour: number; gross: string; order_count: number }[];
    by_weekday?: { weekday: number; gross: string; order_count: number }[];
    by_payment_method?: { payment_method: string; gross: string; order_count: number }[];
    by_order_type?: { order_type: string; gross: string; order_count: number }[];
    by_branch?: { branch_id: number; branch_name: string; gross: string; order_count: number }[];
}

export function fetchSalesReport(filter: ReportFilter): Promise<{ data: SalesReportPayload }> {
    return apiGet<{ data: SalesReportPayload }>(reportPath('sales', filter));
}

// ============================================================
// Customer Report (§5.11.8)
// ============================================================

export interface CustomerReportPayload {
    window: { from: string; to: string; branch_ids: number[] | null };
    top_customers: { customer_id: number; name: string; phone: string | null; total_spend: string; order_count: number }[];
    cohort: { new_count: number; returning_count: number };
    loyalty: {
        points_issued: number;
        points_redeemed: number;
        net_change: number;
        outstanding_liability: number;
    };
}

export function fetchCustomerReport(filter: ReportFilter): Promise<{ data: CustomerReportPayload }> {
    return apiGet<{ data: CustomerReportPayload }>(reportPath('customers', filter));
}

// ============================================================
// Discount Report (§5.11.7)
// ============================================================

export interface DiscountReportPayload {
    window: { from: string; to: string; branch_ids: number[] | null };
    headline: {
        total_discount: string;
        gross_sales: string;
        discount_pct_of_gross: number;
        order_count: number;
        discounted_order_count: number;
    };
    by_branch: { branch_id: number; branch_name: string; total_discount: string; discount_pct: number; order_count: number }[];
    by_rule: { rule_name: string; total_discount: string; order_count: number }[];
    by_staff: { staff_name: string; total_discount: string; order_count: number }[];
    _phase?: { rule_stub?: string; staff_stub?: string };
}

export function fetchDiscountReport(filter: ReportFilter): Promise<{ data: DiscountReportPayload }> {
    return apiGet<{ data: DiscountReportPayload }>(reportPath('discounts', filter));
}

// ============================================================
// Product Performance Report (§5.11.2)
// ============================================================

export interface ProductPerformanceReportPayload {
    window: { from: string; to: string; branch_ids: number[] | null };
    top_by_revenue: { product_id: number; product_name: string; revenue: string; qty: string }[];
    top_by_qty: { product_id: number; product_name: string; revenue: string; qty: string }[];
    slow_movers: { product_id: number; product_name: string; qty: string }[];
    top_addons: { add_on_name_snapshot: string; attach_count: number; revenue: string }[];
    _phase?: { cost_stub?: string };
}

export function fetchProductPerformanceReport(filter: ReportFilter): Promise<{ data: ProductPerformanceReportPayload }> {
    return apiGet<{ data: ProductPerformanceReportPayload }>(reportPath('product-performance', filter));
}

// ============================================================
// Recipe & Cost Report (§5.11.4)
// ============================================================

export interface RecipeCostReportPayload {
    window: { from: string; to: string; consolidated: boolean };
    rows: {
        product_id: number;
        product_name: string;
        base_price: string;
        theoretical_cost: string;
        profit_per_unit: string;
        margin_pct: number;
        recipe_line_count: number;
    }[];
    _phase?: { trend_stub?: string };
}

export function fetchRecipeCostReport(filter: ReportFilter): Promise<{ data: RecipeCostReportPayload }> {
    return apiGet<{ data: RecipeCostReportPayload }>(reportPath('recipe-cost', filter));
}

// ============================================================
// Staff Activity Report (§5.11.10)
// ============================================================

export interface StaffActivityReportPayload {
    window: { from: string; to: string; branch_ids: number[] | null };
    rows: {
        staff_id: number;
        staff_name: string;
        orders_paid: number;
        revenue: string;
        avg_ticket: string;
        voids: number;
        discounts_applied: number;
        hours_logged: string;
    }[];
}

export function fetchStaffActivityReport(filter: ReportFilter): Promise<{ data: StaffActivityReportPayload }> {
    return apiGet<{ data: StaffActivityReportPayload }>(reportPath('staff-activity', filter));
}

// ============================================================
// Inventory Consumption Report (§5.11.3)
// ============================================================

export interface InventoryConsumptionReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null; days_span: number };
    rows: {
        ingredient_id: number;
        ingredient_name: string;
        unit: string;
        consumed: string;
        current_balance: string;
        consumption_per_day: string;
        days_of_stock: number | null;
        below_min_threshold: boolean;
    }[];
    _phase?: { anomaly_stub?: string };
}

export function fetchInventoryConsumptionReport(filter: ReportFilter): Promise<{ data: InventoryConsumptionReportPayload }> {
    return apiGet<{ data: InventoryConsumptionReportPayload }>(reportPath('inventory-consumption', filter));
}

// ============================================================
// Loss / Waste Report (§5.11.5)
// ============================================================

export interface LossWasteReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: { total_value: string; total_qty: string; event_count: number };
    by_branch: { branch_id: number; branch_name: string; value: string; event_count: number }[];
    by_reason: { reason: string; value: string; event_count: number }[];
    top_wasted: { ingredient_id: number; ingredient_name: string; unit: string; total_qty: string; value: string }[];
    _phase?: { shortfall_stub?: string };
}

export function fetchLossWasteReport(filter: ReportFilter): Promise<{ data: LossWasteReportPayload }> {
    return apiGet<{ data: LossWasteReportPayload }>(reportPath('loss-waste', filter));
}

// ============================================================
// Restock / Purchasing Report (§5.11.6)
// ============================================================

export interface RestockPurchasingReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: { total_cost: string; total_qty: string; event_count: number };
    by_supplier: { supplier_id: number | null; supplier_name: string; cost: string; event_count: number }[];
    by_branch: { branch_id: number; branch_name: string; cost: string; event_count: number }[];
    top_purchased: { ingredient_id: number; ingredient_name: string; unit: string; total_qty: string; cost: string }[];
    _phase?: { invoice_stub?: string };
}

export function fetchRestockPurchasingReport(filter: ReportFilter): Promise<{ data: RestockPurchasingReportPayload }> {
    return apiGet<{ data: RestockPurchasingReportPayload }>(reportPath('restock-purchasing', filter));
}

// ============================================================
// Round-Up Donation Report (§5.11.9) -- STUB until Phase 9
// ============================================================

export interface RoundUpDonationReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: { total_raised: string; donation_count: number; opt_in_rate_pct: number };
    by_charity: { charity_id: number; charity_name: string; total_raised: string }[];
    by_branch: { branch_id: number; branch_name: string; total_raised: string }[];
    _phase?: { donation_stub?: string };
}

export function fetchRoundUpDonationReport(filter: ReportFilter): Promise<{ data: RoundUpDonationReportPayload }> {
    return apiGet<{ data: RoundUpDonationReportPayload }>(reportPath('round-up-donation', filter));
}

// ============================================================
// Audit Log Viewer (§5.12)
// ============================================================

export interface AuditLogFilter extends ReportFilter {
    event?: string | null;
    actor_id?: number | null;
    page?: number;
    per_page?: number;
}

export interface AuditLogEntry {
    id: number;
    event: string;
    actor_id: number | null;
    actor_name: string | null;
    actor_email: string | null;
    branch_id: number | null;
    auditable_type: string | null;
    auditable_id: number | null;
    ip_address: string | null;
    // Use `unknown` rather than the recursive JsonValue alias --
    // Vue's template-compiler chokes on the deep recursion when
    // these fields appear in `v-if`/`{{ }}` expressions.
    old_values: unknown;
    new_values: unknown;
    metadata: unknown;
    created_at: string | null;
}

export interface AuditLogPayload {
    window: {
        from: string;
        to: string;
        branch_ids: number[] | null;
        event: string | null;
        actor_id: number | null;
    };
    rows: AuditLogEntry[];
    meta: {
        current_page: number;
        per_page: number;
        last_page: number;
        total: number;
    };
}

export function fetchAuditLog(filter: AuditLogFilter): Promise<{ data: AuditLogPayload }> {
    const base = '/api/reports/audit-log';
    const q = new URLSearchParams();
    q.set('date_from', filter.date_from);
    q.set('date_to', filter.date_to);
    if (filter.event) q.set('event', filter.event);
    if (filter.actor_id !== undefined && filter.actor_id !== null) q.set('actor_id', String(filter.actor_id));
    if (filter.page !== undefined) q.set('page', String(filter.page));
    if (filter.per_page !== undefined) q.set('per_page', String(filter.per_page));
    const url = withBranchScope(`${base}?${q.toString()}`, filter.branch_ids);
    return apiGet<{ data: AuditLogPayload }>(url);
}

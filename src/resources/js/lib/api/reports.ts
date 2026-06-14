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

import { apiDownload, apiGet } from '@/lib/api';

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
        // Laravel's `boolean` validation rule only accepts true/false/1/0/'1'/'0'
        // — the string 'true' (what String(true) yields) is REJECTED, 422-ing
        // every report. Serialize booleans as '1'/'0' so `consolidated` passes.
        q.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
    }
    return withBranchScope(`${base}?${q.toString()}`, filter.branch_ids);
}

// ============================================================
// Report export download (Phase D6)
// ============================================================

export type ReportExportFormat = 'csv' | 'xlsx' | 'pdf';

/**
 * Download a report export (GET reports/{key}/export?format=…) and hand
 * the bytes to the browser as a file. Reuses the report URL builder
 * (booleans as 1/0, branch_ids[] repetition) + the apiDownload blob flow;
 * the server's Content-Disposition filename wins, with a same-shape
 * fallback built client-side.
 */
export async function downloadReportExport(
    key: string,
    filter: ReportFilter,
    format: ReportExportFormat,
): Promise<void> {
    const path = reportPath(`${key}/export`, filter);
    const sep = path.includes('?') ? '&' : '?';
    const { blob, filename } = await apiDownload(`${path}${sep}format=${format}`);

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename ?? `${key}-report_${filter.date_from}_to_${filter.date_to}.${format}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
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
        /** PT — total tax PAID on purchases in the window. */
        purchase_tax_paid: string;
        /** PT — whether that tax was credited back into net profit. */
        purchase_tax_recoverable: boolean;
        net_profit: string;
        order_count: number;
        refund_count: number;
        avg_ticket: string;
    };
    by_hour?: { hour: number; gross: string; count: number }[];
    by_weekday?: { weekday: number; gross: string; count: number }[];
    by_hour_weekday?: { weekday: number; hour: number; gross: string; count: number }[];
    by_payment_method?: { method: string; amount: string; count: number }[];
    by_order_type?: { type: string; gross: string; count: number }[];
    /** P-F9 — offer applications (offer_id-tagged discount rows) per offer. */
    by_offer?: { offer_id: number; name: string; amount: string; count: number }[];
    by_branch?: { branch_id: number; branch_name: string; gross: string; count: number }[];
    /** PD5 — cash-model expense breakdown (every purchase + manual expense). */
    by_expense_category?: { category: string; name: string; name_ar: string | null; amount: string; count: number }[];
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

/** One product row — the REAL server shape (qty_sold, not qty). */
export interface ProductPerformanceRow {
    product_id: number;
    product_name: string;
    qty_sold: string;
    revenue: string;
    recipe_cost: string;
    profit: string;
    margin_pct: number;
    /** P-G3 — sold as an add-on inside other products. */
    addon_units?: string;
    addon_revenue?: string;
}

export interface ProductPerformanceReportPayload {
    window: { from: string; to: string; branch_ids: number[] | null };
    top_by_revenue: ProductPerformanceRow[];
    top_by_qty: ProductPerformanceRow[];
    slow_movers: ProductPerformanceRow[];
    top_addons: { add_on_name: string; attach_count: number; attach_revenue: string }[];
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
        /** Phase A (Additions §2.11) — the LAST day-end count in the window. */
        counted_units: string | null;
        /** Net variance written by the window's counts (negative = shortfall). */
        variance_units: string | null;
        last_counted_at: string | null;
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
    /**
     * Portion-control variance (Additions §1.2): theoretical consumption
     * from sales recipes vs total stock depletion, per ingredient.
     * variance_pct = shortfall ÷ sales × 100 (null when no sales).
     */
    shortfall: {
        ingredient_id: number;
        ingredient_name: string;
        unit: string;
        sales_consumption: string;
        total_depletion: string;
        shortfall: string;
        variance_pct: string | null;
    }[];
    /** P-G1.5 — day-end product waste + give-aways (pieces, cost-based value). */
    product_dispositions?: {
        product_id: number;
        product_name: string;
        movement_type: 'waste' | 'give_away' | string;
        total_qty: string;
        value: string;
        event_count: number;
    }[];
    /** Phase B — voided orders by reason code / by staff (Additions §1.2). */
    voids_by_reason: { reason: string; void_count: number; order_value: string }[];
    voids_by_staff: { staff_id: number; staff_name: string; void_count: number; order_value: string }[];
    _phase?: { shortfall_stub?: string };
}

// ============================================================
// Phase B — Comp Report (Additions §1.2)
// ============================================================

export interface CompReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: { total_value: string; comp_count: number; comped_order_count: number };
    /** P-F5 — gifted lines (is_gift rows), split out of the reason analysis. */
    gifts: { total_value: string; gift_count: number; gifted_order_count: number };
    by_reason: { code: string; name: string; value: string; comp_count: number }[];
    by_branch: { branch_id: number; branch_name: string; value: string; comp_count: number }[];
    by_staff: { staff_id: number; staff_name: string; value: string; comp_count: number }[];
    recent: {
        id: number;
        reason: string;
        amount: string;
        scope: 'line' | 'order';
        note: string | null;
        applied_at: string | null;
        order_uuid: string;
    }[];
}

export function fetchCompReport(filter: ReportFilter): Promise<{ data: CompReportPayload }> {
    return apiGet<{ data: CompReportPayload }>(reportPath('comps', filter));
}

// ============================================================
// Phase B — Shift Report (cash variance per shift)
// ============================================================

export interface ShiftReportRow {
    id: number;
    uuid: string;
    status: string;
    branch_name: string;
    staff_name: string | null;
    opened_at: string;
    closed_at: string | null;
    opening_cash: string;
    expected_cash: string | null;
    counted_cash: string | null;
    variance: string | null;
    cash_collected: string | null;
}

export interface ShiftReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    summary: { shift_count: number; closed_count: number; total_variance: string; total_short: string };
    shifts: ShiftReportRow[];
}

export function fetchShiftReport(filter: ReportFilter): Promise<{ data: ShiftReportPayload }> {
    return apiGet<{ data: ShiftReportPayload }>(reportPath('shifts', filter));
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
    /** P-G4 — branch_id null = the central "Warehouse" bucket. */
    by_branch: { branch_id: number | null; branch_name: string; cost: string; event_count: number }[];
    top_purchased: { ingredient_id: number; ingredient_name: string; unit: string; total_qty: string; cost: string }[];
    _phase?: { invoice_stub?: string };
}

export function fetchRestockPurchasingReport(filter: ReportFilter): Promise<{ data: RestockPurchasingReportPayload }> {
    return apiGet<{ data: RestockPurchasingReportPayload }>(reportPath('restock-purchasing', filter));
}

// ============================================================
// Payouts / Commission Breakdown Report
// ============================================================
//
// Per-merchant commission split: every paid sale is divided into
// the platform fee, the bank fee (card money only), an "other"
// bucket, and the merchant's net take-home. Money values arrive as
// decimal-3 strings; num_sales is an int.

export type PayoutPartyType = 'platform' | 'bank' | 'other' | 'merchant';

export interface PayoutBreakdownPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: {
        gross: string;
        platform: string;
        bank: string;
        other: string;
        merchant_net: string;
        num_sales: number;
    };
    parties: { party_type: PayoutPartyType; total: string }[];
    by_branch: {
        branch_id: number;
        gross: string;
        platform: string;
        bank: string;
        other: string;
        merchant_net: string;
        num_sales: number;
    }[];
}

export function fetchPayoutBreakdown(filter: ReportFilter): Promise<{ data: PayoutBreakdownPayload }> {
    return apiGet<{ data: PayoutBreakdownPayload }>(reportPath('payouts', filter));
}

// ---- Payout history (stateful payouts the platform creates/settles) ----
//
// The breakdown above is a live date-window aggregation; this lists the
// actual payout records (own company only, reports.view gated). Money
// values are decimal-3 strings; sales_count is an int. Paginated 50/page
// but merchants have few payouts, so callers just read `.data`.

export type MerchantPayoutStatus = 'pending' | 'paid' | 'cancelled';

export interface MerchantPayoutRow {
    uuid: string;
    period_from: string;        // ISO date
    period_to: string;          // ISO date
    status: MerchantPayoutStatus;
    gross_amount: string;       // decimal-3
    platform_amount: string;    // decimal-3
    bank_amount: string;        // decimal-3
    other_amount: string;       // decimal-3
    net_amount: string;         // decimal-3
    sales_count: number;
    reference: string | null;
    paid_at: string | null;     // ISO datetime
    created_at: string | null;  // ISO datetime
}

export function fetchMyPayouts(opts?: { status?: MerchantPayoutStatus }): Promise<{ data: MerchantPayoutRow[]; meta?: Record<string, unknown> }> {
    const url = opts?.status ? `/api/payouts?status=${opts.status}` : '/api/payouts';
    return apiGet<{ data: MerchantPayoutRow[]; meta?: Record<string, unknown> }>(url);
}

// ============================================================
// Round-Up Donation Report (§5.11.9)
// ============================================================
//
// Live round-up donations: every paid sale can round up to charity.
// `headline` counts donations by lifecycle (total raised counts the
// settled "success" ones; pending + failed are surfaced alongside).
// `by_branch` lists SUCCESS-only totals, sorted by raised desc, and
// carries only branch_id (resolve names client-side). `by_status`
// breaks every donation down by lifecycle status. Money values are
// decimal-3 strings; counts are ints.

export type RoundUpDonationStatus = 'success' | 'pending' | 'fail' | 'void';

export interface RoundUpDonationReportPayload {
    window: { from: string; to: string; consolidated: boolean; branch_ids: number[] | null };
    headline: {
        total_raised: string;
        donation_count: number;
        pending_count: number;
        failed_count: number;
    };
    by_branch: { branch_id: number; total_raised: string; donation_count: number }[];
    by_status: { status: RoundUpDonationStatus; total: string; count: number }[];
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

// ---- Sales / Orders list (not an aggregate report) -------------

export interface OrderListFilter extends ReportFilter {
    status?: string | null;
    page?: number;
    per_page?: number;
}

export interface OrderListRow {
    id: number;
    uuid: string;
    /** P-F8 — printed receipt number; null falls back to the short uuid. */
    receipt_number: string | null;
    branch_id: number;
    branch_name: string | null;
    order_type: string | null;
    status: string | null;
    source: string | null;
    customer_name: string | null;
    plate_number: string | null;
    items_count: number;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    grand_total: string;
    opened_at: string | null;
    closed_at: string | null;
}

export interface OrderListPayload {
    window: { from: string; to: string; branch_ids: number[] | null; status: string | null };
    totals: { count: number; grand_total: string };
    rows: OrderListRow[];
    meta: { current_page: number; per_page: number; last_page: number; total: number };
}

export function fetchOrders(filter: OrderListFilter): Promise<{ data: OrderListPayload }> {
    const q = new URLSearchParams();
    q.set('date_from', filter.date_from);
    q.set('date_to', filter.date_to);
    if (filter.status) q.set('status', filter.status);
    if (filter.page !== undefined) q.set('page', String(filter.page));
    if (filter.per_page !== undefined) q.set('per_page', String(filter.per_page));
    const url = withBranchScope(`/api/orders?${q.toString()}`, filter.branch_ids);
    return apiGet<{ data: OrderListPayload }>(url);
}

// ---- Single-order detail (v2 #2) -------------------------------

export interface OrderDetailDiscount {
    name: string;
    amount_type: string | null;
    amount: string;
    applied_at: string | null;
}

export interface OrderDetailItem {
    id: number;
    product_name: string;
    qty: string;
    unit_price: string;
    line_discount: string;
    line_total: string;
    notes: string | null;
    addons: { name: string; price_delta: string }[];
    discounts: OrderDetailDiscount[];
}

export interface OrderDetailPayment {
    method: string | null;
    amount: string;
    change_given: string | null;
    status: string | null;
    softpos_auth_code: string | null;
    softpos_reference: string | null;
    captured_at: string | null;
}

export interface OrderDetailPayload {
    order: {
        id: number;
        uuid: string;
        /** P-F8 — printed receipt number; null falls back to the short uuid. */
        receipt_number: string | null;
        order_type: string | null;
        status: string | null;
        source: string | null;
        plate_number: string | null;
        note: string | null;
        opened_at: string | null;
        closed_at: string | null;
        branch: { id: number; name: string } | null;
        customer: { id: number; name: string; phone: string | null } | null;
        staff: { id: number; name: string } | null;
        totals: { subtotal: string; discount_total: string; tax_total: string; grand_total: string };
    };
    items: OrderDetailItem[];
    order_discounts: OrderDetailDiscount[];
    payments: OrderDetailPayment[];
    loyalty: {
        points_earned: number;
        points_redeemed: number;
        stamps_earned: number;
        stamps_redeemed: number;
        transactions: { type: string; points_delta: number; stamps_delta: number; occurred_at: string | null }[];
    };
}

export function fetchOrderDetail(uuid: string): Promise<{ data: OrderDetailPayload }> {
    return apiGet<{ data: OrderDetailPayload }>(`/api/orders/${uuid}`);
}

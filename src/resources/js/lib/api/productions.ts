/**
 * Kitchen production history API (P-G1) — mirror of
 * {@link \App\Http\Controllers\Pos\ProductionsController} (read-only).
 *
 * Batches are created / finished / cancelled exclusively from the POS
 * device through pos_api (production is online-only); the portal audits
 * them. Server gate: production.view (403 otherwise). Quantities arrive
 * as strings (decimal casts) — never parseFloat for stock counts.
 */

import { apiGet } from '@/lib/api';

export interface ProductionLine {
    ingredient_name: string | null;
    ingredient_name_ar: string | null;
    /** Total consumed for the whole batch (decimal string, positive). */
    quantity: string;
    unit: string;
    /** false = locked recipe x quantity; true = declared extra. */
    is_extra: boolean;
}

export interface Production {
    id: number;
    uuid: string;
    status: 'in_progress' | 'finished' | 'cancelled';
    /** Pieces in the batch (decimal string). */
    quantity: string;
    product: { uuid: string | null; name: string | null; name_ar: string | null };
    branch: { uuid: string | null; name: string | null };
    started_by: string | null;
    finished_by: string | null;
    cancelled_by: string | null;
    cancel_approved_by: string | null;
    started_at: string | null;
    finished_at: string | null;
    /** P-G1.5 — the chef's per-batch expiry (null = never expires). */
    expires_at: string | null;
    cancelled_at: string | null;
    duration_seconds: number | null;
    lines: ProductionLine[];
}

export interface ProductionPage {
    data: Production[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ProductionFilters {
    branch_uuid?: string;
    status?: string;
    /** YYYY-MM-DD (inclusive, on started_at). */
    from?: string;
    /** YYYY-MM-DD (inclusive, on started_at). */
    to?: string;
    page?: number;
    per_page?: number;
}

export function listProductions(filters: ProductionFilters = {}): Promise<ProductionPage> {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    }
    const qs = params.toString();
    return apiGet<ProductionPage>(`/api/productions${qs ? `?${qs}` : ''}`);
}

/* ── Graphical-view aggregates (KP) ─────────────────────────────────────── */

export interface ProductionTotals {
    batches: number;
    /** Pieces produced across the window (decimal string). */
    pieces: string;
    finished: number;
    in_progress: number;
    cancelled: number;
    /** Mean finished-batch duration (seconds). */
    avg_duration_seconds: number;
}

export interface ProductionByProduct {
    product_name: string;
    product_name_ar: string | null;
    batches: number;
    pieces: string;
}

export interface ProductionByStaff {
    staff_name: string;
    batches: number;
    pieces: string;
}

export interface ProductionByDay {
    date: string;
    batches: number;
    pieces: string;
}

export interface ProductionStatusMix {
    status: string;
    count: number;
}

/** One batch on the Gantt timeline (start → finish). */
export interface ProductionTimelineEntry {
    uuid: string;
    product_name: string | null;
    product_name_ar: string | null;
    status: 'in_progress' | 'finished' | 'cancelled';
    quantity: string;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    duration_seconds: number | null;
    staff_name: string | null;
}

/**
 * The shape rendered by the shared KitchenProductionCharts component. The
 * branch-activity payload carries this MINUS `by_staff` (hence optional).
 */
export interface KitchenProductionSummary {
    totals: ProductionTotals;
    by_product: ProductionByProduct[];
    by_day: ProductionByDay[];
    status_mix: ProductionStatusMix[];
    timeline: ProductionTimelineEntry[];
    by_staff?: ProductionByStaff[];
}

export interface ProductionSummary extends KitchenProductionSummary {
    by_staff: ProductionByStaff[];
}

export interface ProductionSummaryFilters {
    branch_uuid?: string;
    status?: string;
    /** YYYY-MM-DD (inclusive, on started_at). */
    from?: string;
    /** YYYY-MM-DD (inclusive, on started_at). */
    to?: string;
}

export function getProductionSummary(filters: ProductionSummaryFilters = {}): Promise<{ data: ProductionSummary }> {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    }
    const qs = params.toString();
    return apiGet<{ data: ProductionSummary }>(`/api/productions/summary${qs ? `?${qs}` : ''}`);
}

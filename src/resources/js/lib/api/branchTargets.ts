/**
 * Branch performance targets API (P-G8) — mirror of
 * {@link \App\Http\Controllers\Pos\BranchTargetsController}.
 *
 * A target is an amount per day|week|month evaluated over back-to-back
 * windows of N periods (cumulative goal = amount x N). Money values are
 * OMR decimal-3 strings. Both GETs lazily finalize fully-elapsed windows
 * server-side (there is no scheduler).
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type TargetPeriod = 'day' | 'week' | 'month';

export interface TargetWindowRow {
    window_start: string;
    window_end: string;
    goal_amount: string;
    actual_amount: string;
    hit: boolean;
}

export interface CurrentWindow {
    window_start: string;
    window_end: string;
    elapsed_periods: number;
    goal: string;
    actual: string;
    progress_pct: number;
}

export interface BranchTarget {
    uuid: string;
    branch_uuid: string | null;
    branch_name: string | null;
    period: TargetPeriod;
    amount: string;
    window_periods: number;
    starts_on: string;
    is_active: boolean;
    current: CurrentWindow | null;
    hit_count: number;
    window_count: number;
    history: TargetWindowRow[];
}

export interface BranchPerformanceRow {
    target_uuid: string;
    branch_uuid: string | null;
    branch_name: string | null;
    period: TargetPeriod;
    window_periods: number;
    window_start: string;
    window_end: string;
    elapsed_periods: number;
    goal: string;
    actual: string;
    progress_pct: number;
    hit_count: number;
    window_count: number;
    last_window: TargetWindowRow | null;
}

export interface RecentMiss {
    branch_name: string | null;
    window_start: string;
    window_end: string;
    goal_amount: string;
    actual_amount: string;
}

export interface BranchPerformancePayload {
    data: BranchPerformanceRow[];
    recent_misses: RecentMiss[];
}

export interface CreateBranchTargetPayload {
    branch_uuid: string;
    period: TargetPeriod;
    amount: number;
    window_periods: number;
    starts_on: string;
}

export interface UpdateBranchTargetPayload {
    amount?: number;
    is_active?: boolean;
}

export function listBranchTargets(): Promise<{ data: BranchTarget[] }> {
    return apiGet<{ data: BranchTarget[] }>('/api/branch-targets');
}

/** The dashboard widget (auth-only; F5-scoped server-side). */
export function fetchBranchPerformance(): Promise<BranchPerformancePayload> {
    return apiGet<BranchPerformancePayload>('/api/branch-targets/performance');
}

export function createBranchTarget(payload: CreateBranchTargetPayload): Promise<{ data: BranchTarget }> {
    return apiPost<{ data: BranchTarget }>('/api/branch-targets', payload as unknown as JsonValue);
}

export function updateBranchTarget(uuid: string, payload: UpdateBranchTargetPayload): Promise<{ data: BranchTarget }> {
    return apiPatch<{ data: BranchTarget }>(`/api/branch-targets/${uuid}`, payload as unknown as JsonValue);
}

export function deleteBranchTarget(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/branch-targets/${uuid}`);
}

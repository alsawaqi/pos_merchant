/**
 * Expenses API — Phase 6 backfill (blueprint §5.10).
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ExpensesController}.
 *
 * Permission gates server-side: expenses.view for the list,
 * expenses.manage for log / review / reject.
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

export type ExpenseCategory = 'utilities' | 'supplies' | 'maintenance' | 'salaries' | 'other';
export type ExpenseStatus = 'recorded' | 'reviewed' | 'rejected';

export interface Expense {
    id: number;
    uuid: string;
    branch_id: number | null;
    branch_name: string | null;
    category: ExpenseCategory;
    /** Decimal:3 OMR string. Never parseFloat for money. */
    amount: string;
    note: string | null;
    receipt_photo_path: string | null;
    logged_by_pos_staff_id: number | null;
    logged_by_portal_user_id: number | null;
    logged_by_name: string | null;
    logged_at: string | null;
    status: ExpenseStatus;
    reviewed_by_portal_user_id: number | null;
    reviewed_by_name: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ExpenseListResponse {
    data: Expense[];
    meta: PaginationMeta;
}

export interface ExpenseFilters {
    status?: ExpenseStatus | '';
    category?: ExpenseCategory | '';
    branch_id?: number | null;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

export interface LogExpensePayload {
    branch_id: number | null;
    category: ExpenseCategory;
    amount: string;
    note?: string | null;
    receipt_photo_path?: string | null;
    logged_at?: string;
}

export const EXPENSE_CATEGORIES: ExpenseCategory[] = [
    'utilities',
    'supplies',
    'maintenance',
    'salaries',
    'other',
];

export const EXPENSE_STATUSES: ExpenseStatus[] = ['recorded', 'reviewed', 'rejected'];

function buildQuery(filters: ExpenseFilters): string {
    const q = new URLSearchParams();
    if (filters.status) q.set('status', filters.status);
    if (filters.category) q.set('category', filters.category);
    if (filters.branch_id) q.set('branch_id', String(filters.branch_id));
    if (filters.date_from) q.set('date_from', filters.date_from);
    if (filters.date_to) q.set('date_to', filters.date_to);
    if (filters.page) q.set('page', String(filters.page));
    if (filters.per_page) q.set('per_page', String(filters.per_page));
    const s = q.toString();
    return s ? `?${s}` : '';
}

export function listExpenses(filters: ExpenseFilters = {}): Promise<ExpenseListResponse> {
    return apiGet<ExpenseListResponse>(`/api/expenses${buildQuery(filters)}`);
}

export function logExpense(payload: LogExpensePayload): Promise<{ data: Expense }> {
    return apiPost<{ data: Expense }>('/api/expenses', payload as unknown as JsonValue);
}

export function reviewExpense(uuid: string, reviewNote?: string): Promise<{ data: Expense }> {
    return apiPost<{ data: Expense }>(`/api/expenses/${uuid}/review`, {
        review_note: reviewNote ?? null,
    } as unknown as JsonValue);
}

export function rejectExpense(uuid: string, reviewNote: string): Promise<{ data: Expense }> {
    return apiPost<{ data: Expense }>(`/api/expenses/${uuid}/reject`, {
        review_note: reviewNote,
    } as unknown as JsonValue);
}

/**
 * Expense Categories API — company-level CRUD (v2 #7).
 *
 * Mirrors {@link \App\Http\Controllers\Pos\ExpenseCategoryController}.
 * Server gates: expenses.view for read, expenses.manage for writes.
 * The Main POS fetches the active set via /device/config; logged
 * expenses store the category `key`.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export interface ExpenseCategory {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    /** Stable slug stored on each expense; generated from the name on create. */
    key: string;
    is_active: boolean;
    sort_order: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface CreateExpenseCategoryPayload {
    name: string;
    name_ar?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export interface UpdateExpenseCategoryPayload {
    name?: string;
    name_ar?: string | null;
    is_active?: boolean;
    sort_order?: number;
}

export function listExpenseCategories(): Promise<{ data: ExpenseCategory[] }> {
    return apiGet<{ data: ExpenseCategory[] }>('/api/expense-categories');
}

export function createExpenseCategory(payload: CreateExpenseCategoryPayload): Promise<{ data: ExpenseCategory }> {
    return apiPost<{ data: ExpenseCategory }>('/api/expense-categories', payload as unknown as JsonValue);
}

export function updateExpenseCategory(uuid: string, payload: UpdateExpenseCategoryPayload): Promise<{ data: ExpenseCategory }> {
    return apiPatch<{ data: ExpenseCategory }>(`/api/expense-categories/${uuid}`, payload as unknown as JsonValue);
}

export function deleteExpenseCategory(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/expense-categories/${uuid}`);
}

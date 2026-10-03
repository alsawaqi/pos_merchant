/**
 * LAUNCH-P4 B6 — menu import (template → preview → save) and export.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\MenuImportController}. The file is
 * sent again on save; the server plans it again and saves in one transaction,
 * or answers 422 with the preview while a row has an error.
 */

import { apiDownload, apiUpload } from '@/lib/api';

export type ImportAction = 'new' | 'update' | 'unchanged' | 'error';

export interface ImportIssue {
    /** Stable code, shown through menu_import.issues.<code>. */
    code: string;
    field: string | null;
    params: Record<string, string | number>;
    level: 'error' | 'info';
}

export interface ImportRow {
    row: number;
    action: ImportAction;
    name: string;
    category: string | null;
    price: string | null;
    sku: string | null;
    product_uuid: string | null;
    changes: string[];
    issues: ImportIssue[];
}

export interface ImportPreview {
    summary: { total: number; new: number; update: number; unchanged: number; error: number; new_categories: number };
    new_categories: { name: string; name_ar: string | null }[];
    rows: ImportRow[];
}

export interface ImportResult {
    saved: boolean;
    created: number;
    updated: number;
    unchanged: number;
    categories_created: number;
    preview: ImportPreview;
}

function form(file: File, createCategories: boolean): FormData {
    const data = new FormData();
    data.append('file', file, file.name);
    data.append('create_categories', createCategories ? '1' : '0');
    return data;
}

export function previewMenuImport(file: File, createCategories: boolean): Promise<{ data: ImportPreview }> {
    return apiUpload<{ data: ImportPreview }>('/api/products/import/preview', form(file, createCategories));
}

export function commitMenuImport(file: File, createCategories: boolean): Promise<{ data: ImportResult }> {
    return apiUpload<{ data: ImportResult }>('/api/products/import/commit', form(file, createCategories));
}

async function save(url: string, fallbackName: string): Promise<void> {
    const { blob, filename } = await apiDownload(url);
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = filename ?? fallbackName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(href);
}

export function downloadMenuTemplate(): Promise<void> {
    return save('/api/products/import/template', 'menu-template.xlsx');
}

export function downloadMenuExport(format: 'xlsx' | 'csv'): Promise<void> {
    return save(`/api/products/export?format=${format}`, `menu.${format}`);
}

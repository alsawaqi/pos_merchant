/**
 * Catalogue API — categories + products.
 *
 * Mirrors {@link \App\Http\Controllers\Pos\CategoriesController}
 * + {@link \App\Http\Controllers\Pos\ProductsController}.
 *
 * Money columns come back as strings (Laravel decimal cast).
 * Frontend treats them as opaque strings — never parseFloat()
 * because OMR 3-decimal precision matters.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type CategoryStatus = 'active' | 'inactive';
export type ProductStatus = 'active' | 'inactive';

export interface Category {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    description: string | null;
    image_url: string | null;
    display_order: number;
    status: CategoryStatus | null;
    products_count: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Product {
    id: number;
    uuid: string;
    category_id: number | null;
    /** Present when the controller eager-loaded category. */
    category?: { id: number; uuid: string; name: string } | null;
    sku: string | null;
    barcode: string | null;
    name: string;
    name_ar: string | null;
    description: string | null;
    image_url: string | null;
    /** OMR with 3 decimals — keep as string for precision. */
    base_price: string;
    cost_price: string | null;
    /** Percentage (5.00 = 5%). null = inherit company default. */
    tax_rate: string | null;
    display_order: number;
    status: ProductStatus | null;
    created_at: string | null;
    updated_at: string | null;
}

// ---- Category payloads ------------------------------------------

export interface CreateCategoryPayload {
    name: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    display_order?: number;
}

export interface UpdateCategoryPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    display_order?: number;
    status?: CategoryStatus;
}

// ---- Product payloads -------------------------------------------

export interface CreateProductPayload {
    name: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    category_id?: number | null;
    sku?: string | null;
    barcode?: string | null;
    base_price: string | number;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    display_order?: number;
}

export interface UpdateProductPayload {
    name?: string;
    name_ar?: string | null;
    description?: string | null;
    image_url?: string | null;
    category_id?: number | null;
    sku?: string | null;
    barcode?: string | null;
    base_price?: string | number;
    cost_price?: string | number | null;
    tax_rate?: string | number | null;
    display_order?: number;
    status?: ProductStatus;
}

// ---- Categories -------------------------------------------------

export function listCategories(): Promise<{ data: Category[] }> {
    return apiGet<{ data: Category[] }>('/api/categories');
}

export function createCategory(payload: CreateCategoryPayload): Promise<{ data: Category }> {
    return apiPost<{ data: Category }>('/api/categories', payload as unknown as JsonValue);
}

export function updateCategory(
    uuid: string,
    payload: UpdateCategoryPayload,
): Promise<{ data: Category }> {
    return apiPatch<{ data: Category }>(
        `/api/categories/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteCategory(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/categories/${uuid}`);
}

// ---- Products ---------------------------------------------------

export function listProducts(filters?: { category?: string }): Promise<{ data: Product[] }> {
    const qs = filters?.category ? `?category=${encodeURIComponent(filters.category)}` : '';
    return apiGet<{ data: Product[] }>(`/api/products${qs}`);
}

export function createProduct(payload: CreateProductPayload): Promise<{ data: Product }> {
    return apiPost<{ data: Product }>('/api/products', payload as unknown as JsonValue);
}

export function updateProduct(
    uuid: string,
    payload: UpdateProductPayload,
): Promise<{ data: Product }> {
    return apiPatch<{ data: Product }>(
        `/api/products/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function deleteProduct(uuid: string): Promise<void> {
    return apiDelete<void>(`/api/products/${uuid}`);
}

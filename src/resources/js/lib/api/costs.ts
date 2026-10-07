/**
 * LAUNCH costs & allergens add-on — the portal's cost and allergen endpoints
 * (CostsController, AllergensController).
 */

import { apiGet, apiPost, apiPut, type JsonValue } from '@/lib/api';
import type { ProductAllergens } from '@/lib/allergens';
import type { FoodCostRow } from '@/lib/foodCost';

export interface CostSettings {
    /** "10.00" — a purchase price that moves by at least this % alerts. */
    price_alert_threshold_percent: string;
    /** "30.00" — the company target food cost %. */
    target_food_cost_percent: string;
}

export interface PriceHistoryRow {
    line_id: number;
    receipt_uuid: string;
    receipt_reference: string | null;
    received_at: string;
    supplier: { uuid: string; name: string } | null;
    container_label: string | null;
    pieces: string | null;
    purchase_unit: string | null;
    unit: string | null;
    /** Per BASE unit, 6 decimals. */
    unit_cost: string;
    previous_unit_cost: string | null;
    change: string | null;
    change_pct: number | null;
    alert: boolean;
}

export interface PriceAlertDish {
    product_uuid: string;
    name: string;
    name_ar: string | null;
    product_type: string;
    status: FoodCostRow['status'];
    cost_baisas: number | null;
    food_cost_pct: number | null;
    previous_food_cost_pct: number | null;
    target_pct: number;
    over_target: boolean;
    crossed_target: boolean;
}

export interface PriceAlert {
    line_id: number;
    ingredient: { uuid: string | null; name: string | null; name_ar: string | null; unit: string | null };
    old_unit_cost: string;
    new_unit_cost: string;
    change_pct: number;
    supplier: { uuid: string; name: string } | null;
    received_at: string;
    receipt_uuid: string;
    receipt_reference: string | null;
    seen: boolean;
    seen_at: string | null;
    seen_by: string | null;
    /** null when the user may not see costs. */
    dishes: PriceAlertDish[] | null;
}

export interface FoodCostListRow extends FoodCostRow {
    type: 'product' | 'meal';
    product_id: number;
    product_uuid: string;
    meal_uuid?: string;
    name: string;
    name_ar: string | null;
    product_type: string;
    product_status: string;
    /** K-4 — active and on sale today (what the dashboard counts). */
    on_sale: boolean;
}

export function getCostSettings(): Promise<{ data: CostSettings }> {
    return apiGet('/api/settings/costs');
}

export function updateCostSettings(body: Partial<Record<keyof CostSettings, string | number>>): Promise<{ data: CostSettings }> {
    return apiPut('/api/settings/costs', body as unknown as JsonValue);
}

export function getPriceHistory(ingredientUuid: string): Promise<{ data: PriceHistoryRow[]; meta: { threshold_percent: number; unit: string | null } }> {
    return apiGet(`/api/ingredients/${ingredientUuid}/price-history`);
}

export function listPriceAlerts(params: { days?: number; seen?: 'unseen' | 'all' } = {}): Promise<{ data: PriceAlert[]; meta: { days: number; threshold_percent: number } }> {
    const query = new URLSearchParams();
    if (params.days) query.set('days', String(params.days));
    if (params.seen === 'unseen') query.set('seen', 'unseen');
    const qs = query.toString();
    return apiGet(`/api/price-alerts${qs ? `?${qs}` : ''}`);
}

export function markPriceAlertSeen(lineId: number): Promise<{ data: { line_id: number; seen: boolean; marked_now: boolean } }> {
    return apiPost(`/api/price-alerts/${lineId}/seen`);
}

export function listFoodCosts(overOnly = false): Promise<{ data: FoodCostListRow[]; meta: { company_target_pct: number } }> {
    return apiGet(`/api/food-costs${overOnly ? '?over=1' : ''}`);
}

export function getProductAllergens(productUuid: string): Promise<{ data: ProductAllergens }> {
    return apiGet(`/api/products/${productUuid}/allergens`);
}

/** K-12 — leave may_contain out to keep the saved one (a screen that does not show it). */
export function saveProductAllergens(productUuid: string, body: { contains: string[]; may_contain?: string[] }): Promise<{ data: ProductAllergens }> {
    return apiPut(`/api/products/${productUuid}/allergens`, body as unknown as JsonValue);
}

export function saveIngredientAllergens(ingredientUuid: string, allergens: string[]): Promise<{ data: { allergens: string[]; allergens_all: string[] } }> {
    return apiPut(`/api/ingredients/${ingredientUuid}/allergens`, { allergens });
}

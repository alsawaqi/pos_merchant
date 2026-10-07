/**
 * LAUNCH costs & allergens add-on (tester calls 1 and 2) — how the portal
 * shows a dish's food cost % and a price change. The server works the
 * numbers out (App\Support\Costs\FoodCost / PriceHistory); these helpers only
 * format and classify them.
 */

/** A dish's food cost row (ProductResource.food_cost, GET /api/food-costs). */
export interface FoodCostRow {
    status: 'ok' | 'no_recipe' | 'no_price';
    cost_baisas: number | null;
    cost_complete: boolean;
    price_baisas: number;
    net_price_baisas: number;
    food_cost_pct: number | null;
    target_pct: number;
    target_source: 'product' | 'company';
    over_target: boolean;
    over_by_pct: number | null;
}

export type FoodCostTone = 'over' | 'ok' | 'none';

/** "32.5%", or null when there is no % (no recipe / no price). */
export function pctText(value: number | null | undefined): string | null {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return null;
    return `${Number(value).toFixed(1)}%`;
}

/** "+11.1%" / "−16.7%" for a price change (a real minus sign). */
export function changeText(value: number | null | undefined): string | null {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return null;
    const n = Number(value);
    return `${n > 0 ? '+' : n < 0 ? '−' : ''}${Math.abs(n).toFixed(1)}%`;
}

/** The badge: red over target, green within it, grey with no recipe / price. */
export function foodCostTone(row: FoodCostRow | null | undefined): FoodCostTone {
    if (!row || row.status !== 'ok') return 'none';
    return row.over_target ? 'over' : 'ok';
}

/** Baisas → "0.650" (OMR, 3 decimals), exact. */
export function baisasText(baisas: number | null | undefined): string {
    if (baisas === null || baisas === undefined) return '';
    const sign = baisas < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(baisas));
    return `${sign}${Math.floor(abs / 1000)}.${String(abs % 1000).padStart(3, '0')}`;
}

/**
 * A target typed in a form: '' = the company target (null); otherwise a
 * number above 0 and at most 100 with at most 2 decimals.
 */
export function targetProblem(value: string | number | null | undefined): 'invalid' | 'range' | null {
    const text = String(value ?? '').trim();
    if (text === '') return null;
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(text)) return 'invalid';
    const n = Number(text);
    return n > 0 && n <= 100 ? null : 'range';
}

/** The value sent for a target ('' → null = the company's). */
export function targetPayload(value: string | number | null | undefined): string | null {
    const text = String(value ?? '').trim();
    return text === '' ? null : text;
}

/** A unit cost per base unit shown per kg / l when the base unit is g / ml ("0.600 OMR / kg"). */
export function friendlyUnitCost(cost: string | null | undefined, unit: string | null | undefined): { amount: string; unit: string } {
    const value = Number(cost ?? 0);
    if (unit === 'g' || unit === 'ml') {
        return { amount: (value * 1000).toFixed(3), unit: unit === 'g' ? 'kg' : 'l' };
    }
    return { amount: value.toFixed(3), unit: unit ?? '' };
}

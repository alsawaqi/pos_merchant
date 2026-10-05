/**
 * LAUNCH review add-on (E, owner decision D7) — unit safety for every amount
 * box. Pure, so the node tests load it on its own; the portal wraps it in
 * AmountInput.vue (E1, the live line under the box) and the "Is this right?"
 * confirm dialog (E2, which WARNS and never blocks).
 *
 * E1 — a live translation of the typed amount, shown only when it adds
 * information (fix order B-2: never a whole l / kg turned into ml / g):
 *   2.5 l       → "2 l 500 ml"         (a big unit with a part)
 *   0.5 l       → "500 ml"             (less than one big unit)
 *   23 l        → nothing              (a whole big unit says it already)
 *   1500 ml     → "1.5 l"              (a small unit of 100 or more)
 *   50 ml       → nothing
 *   2 gal       → "7.5708 l"           (the US units in metric)
 *   3 crates    → "36 × bottle 1 l = 36 l" (a container: its leaves + amount)
 *   Counted items get only the container translation.
 *
 * E2 — when an amount looks unrealistic (inventory audit §E; the tester's
 * rules, tunable here with their tests):
 *   recipe line      one portion uses more than 2 kg, 2 l or 50 pieces;
 *   container size   above 100 kg / 100 l, or below 5 g / 5 ml;
 *   purchase         the cost per unit it gives is more than 5 times above or
 *                    below the item's current cost (skipped while "No cost yet");
 *   low-stock        below one of the item's smallest container.
 *   prep batch       one line above 50 kg / 50 l / 500 pieces, or above 10 ×
 *                    the batch's own yield when of the same kind.
 */

export type AmountKind = 'weighed' | 'liquid' | 'counted';

/** The size of ONE of each unit in g (weighed) or ml (liquid). */
const SIZE: Record<string, number> = {
    kg: 1000, g: 1, lb: 453.59237, oz: 28.349523125,
    l: 1000, ml: 1, gal: 3785.411784, 'fl oz': 29.5735295625,
};

export function kindOf(unit: string | null | undefined): AmountKind {
    if (unit === 'g' || unit === 'kg' || unit === 'lb' || unit === 'oz') return 'weighed';
    if (unit === 'ml' || unit === 'l' || unit === 'gal' || unit === 'fl oz') return 'liquid';
    return 'counted';
}

function round(value: number, decimals: number): number {
    const scale = 10 ** decimals;
    return Math.round((value + Number.EPSILON * Math.sign(value)) * scale) / scale;
}

function trim(value: number, decimals = 4): string {
    if (!Number.isFinite(value)) return '';
    const text = round(value, decimals).toFixed(decimals).replace(/\.?0+$/, '');
    return text === '-0' ? '0' : text;
}

function num(value: string | number | null | undefined): number {
    const n = typeof value === 'number' ? value : parseFloat(String(value ?? '').trim());
    return Number.isFinite(n) ? n : NaN;
}

/** An amount in g / ml (canonical) from an amount typed in a unit of the kind; NaN when unknown. */
export function toCanonical(amount: string | number, unit: string): number {
    const n = num(amount);
    const size = SIZE[unit];
    return Number.isFinite(n) && size !== undefined ? n * size : NaN;
}

/**
 * E1 — the translation of an amount typed in a kind unit, or null when there
 * is nothing useful to say (a count unit, a blank or non-positive amount).
 */
export function compoundAmount(amount: string | number, unit: string): string | null {
    const n = num(amount);
    const kind = kindOf(unit);
    if (!Number.isFinite(n) || n <= 0 || kind === 'counted') return null;
    const big = kind === 'weighed' ? 'kg' : 'l';
    const small = kind === 'weighed' ? 'g' : 'ml';
    const canonical = n * (SIZE[unit] ?? 1);

    if (unit === big) {
        const whole = Math.floor(round(n, 6));
        const part = round((n - whole) * 1000, 4);
        if (whole > 0 && part > 0) return `${whole} ${big} ${trim(part)} ${small}`;
        // Fix order B-2 — a whole l / kg is never turned into ml / g.
        return whole === 0 ? `${trim(canonical)} ${small}` : null;
    }
    if (unit === small) {
        // Fix order B-2 — a small amount (under 100 ml / g) says nothing new in l / kg.
        return canonical >= 100 ? `${trim(canonical / 1000)} ${big}` : null;
    }
    // A US unit: say it in metric, the friendly way.
    return canonical >= 1000 ? `${trim(canonical / 1000)} ${big}` : `${trim(canonical)} ${small}`;
}

/** What the E1 line under a box needs about the item's containers. */
export interface SafetyContainer {
    uuid: string;
    token?: string;
    factor: string;
    display_name?: string;
    display_name_ar?: string;
    name: string;
    contains_unit_uuid?: string | null;
    contains_quantity?: string | null;
}

function friendlyStored(quantity: number, storedUnit: string): string {
    if ((storedUnit === 'g' || storedUnit === 'ml') && Math.abs(quantity) >= 1000) {
        return `${trim(quantity / 1000)} ${storedUnit === 'g' ? 'kg' : 'l'}`;
    }
    return `${trim(quantity)} ${storedUnit}`;
}

function label(container: SafetyContainer, locale?: string | null): string {
    return (locale === 'ar' ? container.display_name_ar : undefined) ?? container.display_name ?? container.name;
}

/**
 * E1 — the whole translation line for an amount box: the amount typed in a
 * kind unit ("= 2 l 500 ml") or a container ("= 36 × bottle 1 l = 36 l").
 * `unit` is the box's unit value: '' = the stored unit, a kind unit, or a
 * container token / uuid. Returns the parts to join with " = ".
 */
export function translateAmount(opts: {
    amount: string | number;
    unit: string;
    storedUnit: string;
    containers?: SafetyContainer[];
    locale?: string | null;
}): string[] {
    const n = num(opts.amount);
    if (!Number.isFinite(n) || n <= 0) return [];
    const unit = opts.unit === '' ? opts.storedUnit : opts.unit;
    const container = (opts.containers ?? []).find((c) => c.token === unit || c.uuid === unit || (unit.startsWith('#') && c.token === unit));
    if (container) {
        const parts: string[] = [];
        if (container.contains_unit_uuid) {
            let current = container;
            let perLeaf = 1;
            for (let depth = 0; depth < 4 && current.contains_unit_uuid; depth += 1) {
                const child = (opts.containers ?? []).find((c) => c.uuid === current.contains_unit_uuid);
                if (!child) break;
                perLeaf *= num(current.contains_quantity) || 1;
                current = child;
            }
            parts.push(`${trim(n * perLeaf)} × ${label(current, opts.locale)}`);
        }
        parts.push(friendlyStored(n * num(container.factor), opts.storedUnit));
        return parts;
    }
    if (kindOf(opts.storedUnit) === 'counted') return [];
    const compound = compoundAmount(n, unit);
    return compound === null ? [] : [compound];
}

/** One "Is this right?" warning: an i18n key + its parameters. */
export interface AmountWarning {
    key: string;
    params: Record<string, string>;
}

/** The limit per portion, in g / ml / pieces. */
export const RECIPE_LIMITS: Record<AmountKind, number> = { weighed: 2000, liquid: 2000, counted: 50 };

/**
 * E2 — a recipe / add-on line: one portion uses more than 2 kg, 2 l or 50
 * pieces. Typed in a big unit, it suggests the small one ("One latte would
 * use 200 l. Did you mean 200 ml?").
 */
export function recipeLineWarning(opts: { amount: string | number; unit: string; storedUnit: string; factor?: number; product?: string }): AmountWarning | null {
    const n = num(opts.amount);
    if (!Number.isFinite(n) || n <= 0) return null;
    const kind = kindOf(opts.storedUnit);
    const unit = opts.unit === '' ? opts.storedUnit : opts.unit;
    let perPortion: number;
    if (kind === 'counted') {
        perPortion = n * (opts.factor ?? 1);
    } else {
        const stored = n * (opts.factor ?? (SIZE[unit] !== undefined && SIZE[opts.storedUnit] !== undefined ? SIZE[unit]! / SIZE[opts.storedUnit]! : 1));
        perPortion = stored * (SIZE[opts.storedUnit] ?? 1);
    }
    if (perPortion <= RECIPE_LIMITS[kind]) return null;
    const big = kind === 'weighed' ? 'kg' : 'l';
    const small = kind === 'weighed' ? 'g' : 'ml';
    const amountText = kind === 'counted' ? `${trim(perPortion)} ${opts.storedUnit}` : friendlyStored(perPortion, small);
    const suggestion = kind !== 'counted' && unit === big ? `${trim(n)} ${small}` : '';
    return {
        key: suggestion !== '' ? 'amount_safety.warnings.recipe_suggest' : 'amount_safety.warnings.recipe',
        params: { product: opts.product ?? '', amount: amountText, suggestion },
    };
}

/** E2 — a container holding more than 100 kg / 100 l, or less than 5 g / 5 ml. */
export function containerSizeWarning(factorStored: string | number, storedUnit: string): AmountWarning | null {
    const factor = num(factorStored);
    const kind = kindOf(storedUnit);
    if (!Number.isFinite(factor) || factor <= 0 || kind === 'counted') return null;
    const canonical = factor * (SIZE[storedUnit] ?? 1);
    const amount = friendlyStored(canonical, kind === 'weighed' ? 'g' : 'ml');
    if (canonical > 100000) return { key: 'amount_safety.warnings.container_big', params: { amount } };
    if (canonical < 5) return { key: 'amount_safety.warnings.container_small', params: { amount } };
    return null;
}

/**
 * E2 — a purchase whose cost per stored unit is more than 5 × above or below
 * the item's current cost. Skipped while the item has no cost yet (0) and
 * for a free line.
 */
export function purchaseCostWarning(costPerStored: number | null, currentCostPerStored: string | number | null | undefined): AmountWarning | null {
    const current = num(currentCostPerStored);
    if (costPerStored === null || !Number.isFinite(costPerStored) || costPerStored <= 0 || !Number.isFinite(current) || current <= 0) return null;
    const ratio = costPerStored / current;
    if (ratio > 5) return { key: 'amount_safety.warnings.cost_high', params: { times: trim(ratio, 1) } };
    if (ratio < 1 / 5) return { key: 'amount_safety.warnings.cost_low', params: { times: trim(1 / ratio, 1) } };
    return null;
}

/** E2 — a low-stock threshold below one of the item's smallest container. */
export function thresholdWarning(thresholdStored: string | number | null | undefined, containerFactors: (string | number)[], storedUnit: string): AmountWarning | null {
    const threshold = num(thresholdStored);
    const factors = containerFactors.map(num).filter((f) => Number.isFinite(f) && f > 0);
    if (!Number.isFinite(threshold) || threshold <= 0 || factors.length === 0) return null;
    const smallest = Math.min(...factors);
    return threshold < smallest - 1e-9
        ? { key: 'amount_safety.warnings.threshold_small', params: { amount: friendlyStored(threshold, storedUnit), container: friendlyStored(smallest, storedUnit) } }
        : null;
}

/** The limits for ONE line of a prep batch, in g / ml / pieces (tester call, step 11). */
export const PREP_LIMITS: Record<AmountKind, number> = { weighed: 50000, liquid: 50000, counted: 500 };

/** A prep line of the yield's kind may use up to this many times the batch's own yield. */
export const PREP_YIELD_TIMES = 10;

/**
 * E2 — one line of a prep batch: more than 50 kg / 50 l / 500 pieces, or more
 * than 10 × the batch's own yield when the line is the same kind as the yield
 * (2 l of syrup from 40 l of water?). `amount × factor` is the line in its
 * ingredient's stored unit; the yield is in the prep item's stored unit.
 */
export function prepLineWarning(opts: {
    amount: string | number;
    factor: number;
    storedUnit: string;
    yieldStored: number | null;
    yieldUnit: string;
    item?: string;
}): AmountWarning | null {
    const n = num(opts.amount);
    if (!Number.isFinite(n) || n <= 0 || !Number.isFinite(opts.factor) || opts.factor <= 0) return null;
    const kind = kindOf(opts.storedUnit);
    const stored = n * opts.factor;
    const canonical = kind === 'counted' ? stored : stored * (SIZE[opts.storedUnit] ?? 1);
    const say = (value: number): string => (kind === 'counted' ? `${trim(value)} ${opts.storedUnit}` : friendlyStored(value, kind === 'weighed' ? 'g' : 'ml'));
    if (canonical > PREP_LIMITS[kind]) {
        return { key: 'amount_safety.warnings.prep_batch', params: { item: opts.item ?? '', amount: say(canonical) } };
    }
    const y = opts.yieldStored;
    if (y !== null && Number.isFinite(y) && y > 0 && kindOf(opts.yieldUnit) === kind) {
        const yieldCanonical = kind === 'counted' ? y : y * (SIZE[opts.yieldUnit] ?? 1);
        if (canonical > PREP_YIELD_TIMES * yieldCanonical + 1e-9) {
            return { key: 'amount_safety.warnings.prep_yield', params: { item: opts.item ?? '', amount: say(canonical), made: say(yieldCanonical) } };
        }
    }
    return null;
}

/**
 * Fix order B-2 (E1) — the live line under a container's "holds" boxes on the
 * ingredient form: an amount ("holds 500 ml" → "0.5 l") through the same
 * translation as every amount box, or what a nested container holds in all
 * ("crate holds 12 × bottle 1 l" → "12 l"). `childFactor` is the inner
 * container's size in the stored unit.
 */
export function holdsTranslation(opts: {
    mode: 'amount' | 'nested';
    amount?: string | number;
    unit?: string;
    storedUnit: string;
    childFactor?: string | number | null;
    quantity?: string | number;
}): string[] {
    if (opts.mode === 'nested') {
        const child = num(opts.childFactor);
        const q = num(opts.quantity);
        if (!Number.isFinite(child) || child <= 0 || !Number.isFinite(q) || q <= 0) return [];
        const total = child * q;
        return kindOf(opts.storedUnit) === 'counted' ? [`${trim(total)} ${opts.storedUnit}`] : [friendlyStored(total, opts.storedUnit)];
    }
    return translateAmount({ amount: opts.amount ?? '', unit: opts.unit ?? '', storedUnit: opts.storedUnit });
}

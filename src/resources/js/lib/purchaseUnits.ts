/**
 * LAUNCH-P2 P2-3 — the unit picker on goods received.
 *
 * A receipt line can be entered in the ingredient's base unit, its automatic
 * metric pair (kg↔g, l↔ml), one of its extra units (box, carton…) or its
 * piece unit (token '@piece'), with the PRICE PER THAT UNIT. These helpers
 * build the picker options and the live preview (quantity in the base unit,
 * line total, cost per base unit). The server converts again and is the
 * authority (IngredientUnitConverter + ResolvePurchaseLineUnitAction).
 */

export const PIECE_UNIT = '@piece';

/**
 * What the picker needs from an ingredient (a subset of the API shape).
 * LAUNCH review add-on (A2) — a container with a token is keyed by it and
 * shown by its display name (two "bottle" sizes both show); the count
 * container, once a container row, is not offered again as '@piece'.
 */
export interface PurchaseUnitSource {
    unit: string;
    alt_units?: { name: string; factor: string; uuid?: string; token?: string; display_name?: string; display_name_ar?: string }[];
    auto_units?: { name: string; factor: string }[];
    piece_unit_label?: string | null;
    units_per_piece?: string | null;
    count_container_uuid?: string | null;
}

/** Whether the count container is one of the container rows. */
function countContainerIsRow(ingredient: PurchaseUnitSource): boolean {
    return !!ingredient.count_container_uuid && (ingredient.alt_units ?? []).some((c) => c.uuid === ingredient.count_container_uuid);
}

export interface PurchaseUnitOption {
    /** '' = the base unit; otherwise the unit name sent to the server. */
    value: string;
    label: string;
    /** Base units in ONE of this unit. */
    factor: number;
}

function positive(value: string | null | undefined): number | null {
    const n = parseFloat(String(value ?? ''));
    return Number.isFinite(n) && n > 0 ? n : null;
}

/** Trim a number to at most `decimals` places, no trailing zeros ("25000", "0.0003"). */
export function trimNumber(value: number, decimals: number): string {
    if (!Number.isFinite(value)) return '';
    const fixed = value.toFixed(decimals);
    return fixed.includes('.') ? fixed.replace(/\.?0+$/, '') : fixed;
}

/**
 * LAUNCH item kind, A8 — what a pack holds as people read it: 1000 g or ml
 * and above in kg or l ("crate (12 l)", not "12000 ml"). Kept in step with
 * friendlyAmount in lib/itemKind (each lib loads on its own in the node tests).
 */
function holdsText(factor: number, base: string): string {
    if ((base === 'g' || base === 'ml') && Math.abs(factor) >= 1000) {
        return `${trimNumber(factor / 1000, 4)} ${base === 'g' ? 'kg' : 'l'}`;
    }
    return `${trimNumber(factor, 4)} ${base}`;
}

/** F2 — a stored quantity as people read it ({ quantity: '36', unit: 'l' }, not 36000 ml). */
export function friendlyQuantity(quantity: number, base: string): { quantity: string; unit: string } {
    if ((base === 'g' || base === 'ml') && Math.abs(quantity) >= 1000) {
        return { quantity: trimNumber(quantity / 1000, 4), unit: base === 'g' ? 'kg' : 'l' };
    }
    return { quantity: trimNumber(quantity, 4), unit: base };
}

/** A cost (OMR) at 3 decimals ("0.150"). Fix order B-3 — never more; storage keeps 6. */
function costText(value: number): string {
    const text = (Math.round((value + Number.EPSILON) * 1000) / 1000).toFixed(3);
    return text === '-0.000' ? '0.000' : text;
}

/**
 * F2 — the cost per stored unit as people read it: per kg / l for a g / ml
 * item ("0.150 per l", not "0.00015 per ml"). Kept in step with friendlyCost
 * in lib/itemKind (each lib loads on its own in the node tests).
 */
export function friendlyCostPer(costPerBase: number, base: string): { cost: string; unit: string } {
    const big = base === 'g' || base === 'ml';
    const per = big ? costPerBase * 1000 : costPerBase;
    const unit = base === 'g' ? 'kg' : base === 'ml' ? 'l' : base;
    // Fix order B-3 — a cost that would read 0.000 is said per 1000 of the unit.
    if (per > 0 && costText(per) === '0.000') return { cost: costText(per * 1000), unit: `1000 ${unit}` };
    return { cost: costText(per), unit };
}

/**
 * Item kind G1 — the US units (gallon, fl oz, lb, oz) with their names and
 * the size every picker shows. Kept in step with NON_METRIC_UNITS in
 * lib/itemKind (each lib loads on its own in the node tests).
 */
const US_UNITS: Record<string, { en: string; ar: string; size: string }> = {
    gal: { en: 'gallon', ar: 'جالون', size: '3.785 l' },
    'fl oz': { en: 'fl oz', ar: 'أونصة سائلة', size: '29.57 ml' },
    lb: { en: 'lb', ar: 'رطل', size: '453.6 g' },
    oz: { en: 'oz', ar: 'أونصة', size: '28.35 g' },
};

/**
 * The picker options — F2: what people buy in first: the pack sizes, then
 * the larger unit of the kind (kg / l), the smaller (g / ml), G1 the US
 * units (gallon / fl oz, lb / oz, each saying its size), then the count
 * container. ('' = the stored unit, wherever it falls.) A custom extra unit
 * wins over a metric pair of the same name (the server resolves the same way).
 */
export function purchaseUnitOptions(ingredient: PurchaseUnitSource | null | undefined, locale?: string | null): PurchaseUnitOption[] {
    if (!ingredient) return [];
    const base = ingredient.unit;
    const options: PurchaseUnitOption[] = [];
    const seen = new Set<string>([base]);
    for (const alt of ingredient.alt_units ?? []) {
        const factor = positive(alt.factor);
        const key = alt.token ?? alt.name;
        if (seen.has(key) || factor === null) continue;
        seen.add(key);
        if (alt.token) {
            const label = (locale === 'ar' ? alt.display_name_ar : undefined) ?? alt.display_name ?? `${alt.name} (${holdsText(factor, base)})`;
            options.push({ value: alt.token, label, factor });
            continue;
        }
        seen.add(alt.name);
        options.push({ value: alt.name, label: `${alt.name} (${holdsText(factor, base)})`, factor });
    }
    const metric: PurchaseUnitOption[] = [{ value: '', label: base, factor: 1 }];
    const us: PurchaseUnitOption[] = [];
    for (const auto of ingredient.auto_units ?? []) {
        const factor = positive(auto.factor);
        if (seen.has(auto.name) || factor === null) continue;
        seen.add(auto.name);
        const named = US_UNITS[auto.name];
        if (named) {
            us.push({ value: auto.name, label: `${locale === 'ar' ? named.ar : named.en} (${named.size})`, factor });
        } else {
            metric.push({ value: auto.name, label: auto.name, factor });
        }
    }
    // Larger first: kg before g, l before ml; gallon before fl oz, lb before oz.
    metric.sort((a, b) => b.factor - a.factor);
    us.sort((a, b) => b.factor - a.factor);
    options.push(...metric, ...us);
    const perPiece = positive(ingredient.units_per_piece);
    if (ingredient.piece_unit_label && perPiece !== null && !(base === 'piece' && perPiece === 1) && !countContainerIsRow(ingredient)) {
        options.push({
            value: PIECE_UNIT,
            label: `${ingredient.piece_unit_label} (${holdsText(perPiece, base)})`,
            factor: perPiece,
        });
    }
    return options;
}

/** F2 — a new line starts in the first pack size, else the larger unit (kg / l), else the stored unit. */
export function defaultPurchaseUnit(ingredient: PurchaseUnitSource | null | undefined): string {
    return purchaseUnitOptions(ingredient)[0]?.value ?? '';
}

/** Base units per one of the selected unit (1 when unknown — the server re-validates). */
export function purchaseUnitFactor(options: PurchaseUnitOption[], value: string): number {
    return options.find((o) => o.value === value)?.factor ?? 1;
}

/** The short name of a selected unit for labels ("kg", "box", "bottle", G1 "gal" / "جالون"). */
export function purchaseUnitName(ingredient: PurchaseUnitSource | null | undefined, value: string, locale?: string | null): string {
    if (!ingredient) return '';
    if (value === '' || value === ingredient.unit) return ingredient.unit;
    if (value === PIECE_UNIT) return ingredient.piece_unit_label ?? 'piece';
    if (locale === 'ar' && US_UNITS[value]) return US_UNITS[value]!.ar;
    // Review add-on A2 — a container token reads as the container's name.
    const container = (ingredient.alt_units ?? []).find((c) => c.token === value);
    if (container) return locale === 'ar' && container.display_name_ar ? container.display_name_ar : (container.display_name ?? container.name);
    return value;
}

export interface PurchaseLinePreview {
    /** Quantity in the ingredient's base unit, 4 decimals. */
    baseQuantity: number | null;
    /** Quantity × price per unit, OMR rounded once to 3 decimals. */
    lineCost: number | null;
    /** Price per base unit, 6 decimals. */
    costPerBase: number | null;
}

const round = (value: number, decimals: number): number => {
    const scale = 10 ** decimals;
    return Math.round((value + Number.EPSILON * Math.sign(value)) * scale) / scale;
};

/** The live preview of one line: entered quantity × factor, price → per base unit. */
export function purchaseLinePreview(
    factor: number,
    quantity: string | number,
    unitPrice: string | number | null | undefined,
): PurchaseLinePreview {
    const qty = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? ''));
    if (!Number.isFinite(qty) || qty <= 0 || !(factor > 0)) {
        return { baseQuantity: null, lineCost: null, costPerBase: null };
    }
    const priceText = unitPrice === null || unitPrice === undefined ? '' : String(unitPrice).trim();
    const price = priceText === '' ? null : parseFloat(priceText);
    const validPrice = price !== null && Number.isFinite(price) && price >= 0 ? price : null;

    return {
        baseQuantity: round(qty * factor, 4),
        lineCost: validPrice === null ? null : round(qty * validPrice, 3),
        costPerBase: validPrice === null ? null : round(validPrice / factor, 6),
    };
}

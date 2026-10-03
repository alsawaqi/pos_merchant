/**
 * LAUNCH-P3 P3-1 — the recipe unit picker (product recipes, add-on stock
 * usage, prep recipes).
 *
 * A recipe line is typed in any unit the ingredient knows — its base unit,
 * the metric pair (kg↔g, l↔ml), an extra unit (box, carton…) or its PIECE
 * unit ('@piece', e.g. a loaf = 500 g) — and the server stores the BASE
 * quantity plus the entered unit and quantity, so the editor reopens "5 g"
 * as "5 g" (never "0.005 kg"). These helpers build the options, convert for
 * the live cost, and catch an amount that would round to 0 in the base unit
 * (the server refuses it too) before it is sent.
 */

export const PIECE_UNIT = '@piece';

/** The base unit keeps 4 decimals. */
export const QUANTITY_DECIMALS = 4;

export interface RecipeUnitSource {
    unit: string;
    alt_units?: { name: string; factor: string }[];
    auto_units?: { name: string; factor: string }[];
    piece_unit_label?: string | null;
    /** Fix order 1, K8 — the piece unit's Arabic label, used when the locale is ar. */
    piece_unit_label_ar?: string | null;
    units_per_piece?: string | null;
}

/**
 * Fix order 1, K8 — the piece unit's label in the reader's language: the
 * Arabic label when the locale is Arabic and one is set, else the label.
 */
export function pieceUnitLabel(ingredient: RecipeUnitSource | null | undefined, locale?: string | null): string | null {
    if (!ingredient) return null;
    if (locale === 'ar' && ingredient.piece_unit_label_ar) return ingredient.piece_unit_label_ar;
    return ingredient.piece_unit_label ?? null;
}

export interface RecipeUnitOption {
    /** '' = the base unit; otherwise the unit token sent to the server. */
    value: string;
    label: string;
    /** Base units in ONE of this unit. */
    factor: number;
}

function positive(value: string | null | undefined): number | null {
    const n = parseFloat(String(value ?? ''));
    return Number.isFinite(n) && n > 0 ? n : null;
}

function trim(value: number, decimals: number): string {
    if (!Number.isFinite(value)) return '';
    const fixed = value.toFixed(decimals);
    return fixed.includes('.') ? fixed.replace(/\.?0+$/, '') : fixed;
}

/**
 * LAUNCH item kind, A8 — an amount as people read it: 1000 g or ml and above
 * in kg or l ("12 l", not "12000 ml"), 4 decimals at most. Kept in step with
 * friendlyAmount in lib/itemKind (each lib loads on its own in the node tests).
 */
function friendlyText(quantity: number, unit: string): string {
    if ((unit === 'g' || unit === 'ml') && Math.abs(quantity) >= 1000) {
        return `${trim(quantity / 1000, QUANTITY_DECIMALS)} ${unit === 'g' ? 'kg' : 'l'}`;
    }
    return `${trim(quantity, QUANTITY_DECIMALS)} ${unit}`;
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
 * Base first, then extra units, the metric pair and the piece unit. A custom
 * extra unit wins over a metric pair of the same name (the server resolves
 * the same way).
 */
export function recipeUnitOptions(ingredient: RecipeUnitSource | null | undefined, locale?: string | null): RecipeUnitOption[] {
    if (!ingredient) return [];
    const base = ingredient.unit;
    const options: RecipeUnitOption[] = [{ value: '', label: base, factor: 1 }];
    const seen = new Set<string>([base]);
    for (const alt of ingredient.alt_units ?? []) {
        const factor = positive(alt.factor);
        if (seen.has(alt.name) || factor === null) continue;
        seen.add(alt.name);
        options.push({ value: alt.name, label: `${alt.name} (${friendlyText(factor, base)})`, factor });
    }
    for (const auto of ingredient.auto_units ?? []) {
        const factor = positive(auto.factor);
        if (seen.has(auto.name) || factor === null) continue;
        seen.add(auto.name);
        // G1 — a US unit says its size: "gallon (3.785 l)".
        const us = US_UNITS[auto.name];
        options.push({ value: auto.name, label: us ? `${locale === 'ar' ? us.ar : us.en} (${us.size})` : auto.name, factor });
    }
    const perPiece = positive(ingredient.units_per_piece);
    if (ingredient.piece_unit_label && perPiece !== null && !(base === 'piece' && perPiece === 1)) {
        options.push({
            value: PIECE_UNIT,
            label: `${pieceUnitLabel(ingredient, locale)} (${friendlyText(perPiece, base)})`,
            factor: perPiece,
        });
    }
    return options;
}

/** Base units per one of the selected unit (1 when unknown — the server re-validates). */
export function recipeUnitFactor(ingredient: RecipeUnitSource | null | undefined, value: string): number {
    if (!ingredient || value === '' || value === ingredient.unit) return 1;
    return recipeUnitOptions(ingredient).find((o) => o.value === value)?.factor ?? 1;
}

/** The short name of a unit token for labels ("kg", "box", "loaf"). */
export function recipeUnitName(ingredient: RecipeUnitSource | null | undefined, value: string | null | undefined, locale?: string | null): string {
    if (!ingredient) return value ?? '';
    if (!value || value === ingredient.unit) return ingredient.unit;
    if (value === PIECE_UNIT) return pieceUnitLabel(ingredient, locale) ?? 'piece';
    // G1 — "2 gal" / "2 جالون".
    if (locale === 'ar' && US_UNITS[value]) return US_UNITS[value]!.ar;
    return value;
}

const round = (value: number, decimals: number): number => {
    const scale = 10 ** decimals;
    return Math.round((value + Number.EPSILON * Math.sign(value)) * scale) / scale;
};

/** The typed quantity in the base unit, 4 decimals (null when not a positive number). */
export function toBaseQuantity(factor: number, quantity: string | number): number | null {
    const qty = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? '').trim());
    if (!Number.isFinite(qty) || qty <= 0 || !(factor > 0)) return null;
    return round(qty * factor, QUANTITY_DECIMALS);
}

/** More than 4 decimal places typed (the line could not reopen exactly as typed). */
export function hasTooManyDecimals(quantity: string | number): boolean {
    const text = String(quantity ?? '').trim();
    const dot = text.indexOf('.');
    return dot >= 0 && text.slice(dot + 1).replace(/0+$/, '').length > QUANTITY_DECIMALS;
}

/** A positive amount that rounds to 0 in the base unit — refused (it used to be saved as 0). */
export function roundsToZero(factor: number, quantity: string | number): boolean {
    const qty = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? '').trim());
    if (!Number.isFinite(qty) || qty <= 0 || !(factor > 0)) return false;
    return round(qty * factor, QUANTITY_DECIMALS) === 0;
}

/** The smallest step in the selected unit that the base unit records exactly (0.0001 of the base unit). */
export function smallestEntry(factor: number): string {
    if (!(factor > 0)) return '';
    const minimum = Math.ceil((0.0001 / factor) * 10 ** QUANTITY_DECIMALS - 1e-9) / 10 ** QUANTITY_DECIMALS;
    return trim(minimum, QUANTITY_DECIMALS);
}

/**
 * Rounding to the base unit's 4 decimals would move the amount by more than
 * 1% (0.05 g of a kg ingredient would be stored as 0.1 g) — refused, like on
 * the server. A long extra-unit factor moves it by far less and is fine.
 */
export function roundsInaccurately(factor: number, quantity: string | number): boolean {
    const qty = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? '').trim());
    if (!Number.isFinite(qty) || qty <= 0 || !(factor > 0)) return false;
    const exact = qty * factor;
    const stored = round(exact, QUANTITY_DECIMALS);
    return stored !== 0 && Math.abs(stored - exact) > exact * 0.01 + 1e-12;
}

/** What a stored line reopens as: the entered unit + quantity when the server returned them, else the base. */
export function lineEntry(
    line: { quantity: string; entered_unit?: string | null; entered_quantity?: string | null },
    baseUnit: string | null | undefined,
): { quantity: string; unit: string } {
    if (line.entered_unit && line.entered_quantity) {
        return { quantity: line.entered_quantity, unit: line.entered_unit === baseUnit ? '' : line.entered_unit };
    }
    return { quantity: trimQuantity(line.quantity), unit: '' };
}

/**
 * A8 — a recipe or prep line as people read it: typed in the stored unit (or
 * stored before P3) it shows big amounts in kg or l ("1.5 kg", not "1500 g");
 * typed in another unit it shows exactly as typed ("2 loaf", "150 g").
 */
export function lineAmountText(
    ingredient: RecipeUnitSource | null | undefined,
    unit: string | null | undefined,
    quantity: string | number | null | undefined,
    locale?: string | null,
): string {
    const n = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? ''));
    if (ingredient && (!unit || unit === ingredient.unit) && Number.isFinite(n)) {
        return friendlyText(n, ingredient.unit);
    }
    return `${trimQuantity(quantity)} ${recipeUnitName(ingredient, unit, locale)}`;
}

/** "0.0050" → "0.005", "150.000" → "150". */
export function trimQuantity(quantity: string | number | null | undefined): string {
    const n = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? ''));
    return Number.isFinite(n) ? trim(n, QUANTITY_DECIMALS) : String(quantity ?? '');
}

/** The unit as sent to the server: '' (base) → null. */
export function wireRecipeUnit(selected: string | null | undefined): string | null {
    return selected === null || selected === undefined || selected.trim() === '' ? null : selected;
}

/**
 * Validation of one typed line: null when fine, else the message key and
 * its parameters ('recipe_units.too_small' / 'recipe_units.too_many_decimals'
 * / 'recipe_units.amount_required').
 *
 * Fix order 1, L5 + K9 — a line whose ingredient is chosen but whose amount is
 * blank, 0 (a legacy zero line) or not a positive number is a problem too:
 * "enter an amount or remove this line". It used to be dropped silently on
 * save — and since a save rewrites every line, editing deleted it.
 *
 * @param chosen whether the line has an ingredient picked (defaults to "the ingredient is known")
 */
export function recipeLineProblem(
    ingredient: RecipeUnitSource | null | undefined,
    unit: string,
    quantity: string | number | null | undefined,
    chosen: boolean = ingredient !== null && ingredient !== undefined,
    locale?: string | null,
): { key: string; params: Record<string, string> } | null {
    const text = String(quantity ?? '').trim();
    if (text === '') return chosen ? { key: 'recipe_units.amount_required', params: {} } : null;
    const typed = Number(text);
    if (!Number.isFinite(typed) || typed <= 0) {
        return { key: 'recipe_units.amount_required', params: {} };
    }
    if (hasTooManyDecimals(text)) {
        return { key: 'recipe_units.too_many_decimals', params: {} };
    }
    const factor = recipeUnitFactor(ingredient, unit);
    const amount = `${trimQuantity(text)} ${recipeUnitName(ingredient, unit, locale)}`;
    const step = `${smallestEntry(factor)} ${recipeUnitName(ingredient, unit, locale)}`;
    if (roundsToZero(factor, text)) {
        return { key: 'recipe_units.too_small', params: { amount, base: ingredient?.unit ?? '', minimum: step } };
    }
    if (roundsInaccurately(factor, text)) {
        const stored = toBaseQuantity(factor, text);
        return {
            key: 'recipe_units.too_imprecise',
            params: { amount, stored: `${trimQuantity(stored ?? 0)} ${ingredient?.unit ?? ''}`, step },
        };
    }
    return null;
}

/** One recipe line as the editors hold it ('' ingredient = not picked yet). */
export interface RecipeLineDraft {
    ingredient_uuid: string;
    quantity: string | number | null | undefined;
    unit: string;
}

/**
 * Fix order 1, L5 — whether any line blocks saving: a picked ingredient whose
 * amount is blank, 0 or would not record. A line with nothing picked is
 * simply left out.
 */
export function recipeLinesHaveProblems(
    lines: RecipeLineDraft[],
    find: (uuid: string) => RecipeUnitSource | null | undefined,
): boolean {
    return lines.some((l) => l.ingredient_uuid !== '' && recipeLineProblem(find(l.ingredient_uuid) ?? null, l.unit, l.quantity, true) !== null);
}

/** One add-on stock-usage line as the editor holds it. */
export interface ConsumptionLineDraft {
    type: 'ingredient' | 'product';
    ingredient_uuid?: string;
    product_uuid?: string;
    direction: 'add' | 'remove';
    quantity: string | number;
    unit?: string | null;
}

/**
 * Fix order 1, L5 + K8 — the problem of one add-on stock-usage line, or null:
 * an ingredient line follows {@link recipeLineProblem}; an item line needs a
 * positive amount once the item is picked (pieces keep 3 decimals).
 */
export function consumptionLineProblem(
    line: ConsumptionLineDraft,
    find: (uuid: string) => RecipeUnitSource | null | undefined,
    locale?: string | null,
): { key: string; params: Record<string, string> } | null {
    if (line.type === 'ingredient') {
        const uuid = line.ingredient_uuid ?? '';
        return recipeLineProblem(uuid === '' ? null : (find(uuid) ?? null), line.unit ?? '', line.quantity, uuid !== '', locale);
    }
    if ((line.product_uuid ?? '') === '') return null;
    const text = String(line.quantity ?? '').trim();
    const amount = Number(text);
    if (text === '' || !Number.isFinite(amount) || amount <= 0) return { key: 'recipe_units.amount_required', params: {} };
    const dot = text.indexOf('.');
    if (dot >= 0 && text.slice(dot + 1).replace(/0+$/, '').length > 3) return { key: 'recipe_units.too_many_piece_decimals', params: {} };
    return null;
}

/** Fix order 1, K8 — an option's stock usage is not saved while a line has a problem. */
export function consumptionLinesHaveProblems(
    lines: ConsumptionLineDraft[],
    find: (uuid: string) => RecipeUnitSource | null | undefined,
): boolean {
    return lines.some((l) => consumptionLineProblem(l, find) !== null);
}

/**
 * The lines an option's save sends: every line with something picked (a line
 * with nothing picked is left out). A picked line is never dropped here, even
 * with a blank amount — {@link consumptionLinesHaveProblems} blocks the save
 * first (fix order 1, L5: such a line used to vanish silently).
 */
export function completeConsumptionLines<T extends ConsumptionLineDraft>(lines: T[]): ConsumptionLineDraft[] {
    return lines
        .filter((l) => (l.type === 'ingredient' ? !!l.ingredient_uuid : !!l.product_uuid))
        .map((l) => ({
            type: l.type,
            ingredient_uuid: l.type === 'ingredient' ? l.ingredient_uuid : undefined,
            product_uuid: l.type === 'product' ? l.product_uuid : undefined,
            direction: l.direction,
            quantity: String(l.quantity ?? '').trim(),
            unit: l.type === 'ingredient' ? (l.unit || null) : null,
        }));
}

/** Fix order 1, UI-1 — a money amount (OMR) as shown: 3 decimals. */
export function money(value: string | number | null | undefined): string {
    const n = typeof value === 'number' ? value : parseFloat(String(value ?? ''));
    return Number.isFinite(n) ? round(n, 3).toFixed(3) : '—';
}

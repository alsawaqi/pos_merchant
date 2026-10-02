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
    units_per_piece?: string | null;
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
 * Base first, then extra units, the metric pair and the piece unit. A custom
 * extra unit wins over a metric pair of the same name (the server resolves
 * the same way).
 */
export function recipeUnitOptions(ingredient: RecipeUnitSource | null | undefined): RecipeUnitOption[] {
    if (!ingredient) return [];
    const base = ingredient.unit;
    const options: RecipeUnitOption[] = [{ value: '', label: base, factor: 1 }];
    const seen = new Set<string>([base]);
    for (const alt of ingredient.alt_units ?? []) {
        const factor = positive(alt.factor);
        if (seen.has(alt.name) || factor === null) continue;
        seen.add(alt.name);
        options.push({ value: alt.name, label: `${alt.name} (${trim(factor, 4)} ${base})`, factor });
    }
    for (const auto of ingredient.auto_units ?? []) {
        const factor = positive(auto.factor);
        if (seen.has(auto.name) || factor === null) continue;
        seen.add(auto.name);
        options.push({ value: auto.name, label: auto.name, factor });
    }
    const perPiece = positive(ingredient.units_per_piece);
    if (ingredient.piece_unit_label && perPiece !== null && !(base === 'piece' && perPiece === 1)) {
        options.push({
            value: PIECE_UNIT,
            label: `${ingredient.piece_unit_label} (${trim(perPiece, 4)} ${base})`,
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
export function recipeUnitName(ingredient: RecipeUnitSource | null | undefined, value: string | null | undefined): string {
    if (!ingredient) return value ?? '';
    if (!value || value === ingredient.unit) return ingredient.unit;
    if (value === PIECE_UNIT) return ingredient.piece_unit_label ?? 'piece';
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
 * its parameters ('recipe_units.too_small' / 'recipe_units.too_many_decimals').
 */
export function recipeLineProblem(
    ingredient: RecipeUnitSource | null | undefined,
    unit: string,
    quantity: string | number,
): { key: string; params: Record<string, string> } | null {
    if (String(quantity ?? '').trim() === '') return null;
    if (hasTooManyDecimals(quantity)) {
        return { key: 'recipe_units.too_many_decimals', params: {} };
    }
    const factor = recipeUnitFactor(ingredient, unit);
    const amount = `${trimQuantity(quantity)} ${recipeUnitName(ingredient, unit)}`;
    const step = `${smallestEntry(factor)} ${recipeUnitName(ingredient, unit)}`;
    if (roundsToZero(factor, quantity)) {
        return { key: 'recipe_units.too_small', params: { amount, base: ingredient?.unit ?? '', minimum: step } };
    }
    if (roundsInaccurately(factor, quantity)) {
        const stored = toBaseQuantity(factor, quantity);
        return {
            key: 'recipe_units.too_imprecise',
            params: { amount, stored: `${trimQuantity(stored ?? 0)} ${ingredient?.unit ?? ''}`, step },
        };
    }
    return null;
}

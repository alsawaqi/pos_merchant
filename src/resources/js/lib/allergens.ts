/**
 * LAUNCH costs & allergens add-on (tester call 3) — the 14 allergens, in the
 * server's fixed order (App\Support\Catalogue\Allergens::CODES), and the
 * small rules the pickers follow. The names live in the locales
 * (`allergens.codes.<code>`, English and Arabic).
 */

export const ALLERGEN_CODES = [
    'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
    'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
] as const;

export type AllergenCode = (typeof ALLERGEN_CODES)[number];

/** A product page's allergen block (GET /api/products/{uuid}/allergens). */
export interface ProductAllergens {
    /** Everything it contains: ticked by hand + worked out. */
    contains: string[];
    /** "May contain", never repeating `contains`. */
    may_contain: string[];
    /** Worked out from the recipe, prep items, components or combo items — locked. */
    derived: string[];
    own_contains: string[];
    own_may_contain: string[];
}

/** Known codes only, each once, in the fixed order. */
export function normaliseAllergens(codes: readonly string[]): AllergenCode[] {
    const set = new Set(codes);
    return ALLERGEN_CODES.filter((code) => set.has(code));
}

/** Tick or untick one code; a locked code never changes. */
export function toggleAllergen(codes: readonly string[], code: string, locked: readonly string[] = []): AllergenCode[] {
    if (locked.includes(code)) return normaliseAllergens(codes);
    return codes.includes(code)
        ? normaliseAllergens(codes.filter((c) => c !== code))
        : normaliseAllergens([...codes, code]);
}

/**
 * What the product page sends: its own "contains" ticks (never the worked-out
 * ones, which are not the merchant's to set) and its "may contain" ticks
 * (never something it contains).
 */
export function productAllergenPayload(derived: readonly string[], contains: readonly string[], mayContain: readonly string[]): { contains: AllergenCode[]; may_contain: AllergenCode[] } {
    const own = normaliseAllergens(contains.filter((c) => !derived.includes(c)));
    const all = new Set([...derived, ...own]);
    return { contains: own, may_contain: normaliseAllergens(mayContain.filter((c) => !all.has(c))) };
}

/** Two tick lists hold the same codes. */
export function sameAllergens(a: readonly string[], b: readonly string[]): boolean {
    const left = normaliseAllergens(a);
    const right = normaliseAllergens(b);
    return left.length === right.length && left.every((code, i) => code === right[i]);
}

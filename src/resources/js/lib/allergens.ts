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
 * What the product page sends: the merchant's OWN ticks exactly as they are
 * (fix order 1, K-1). A hand tick is kept even when the recipe brings the
 * same allergen today — if the recipe changes later, the hand tick must still
 * be there. The worked-out ones are never sent (the server works them out).
 */
export function productAllergenPayload(contains: readonly string[], mayContain: readonly string[]): { contains: AllergenCode[]; may_contain: AllergenCode[] } {
    return { contains: normaliseAllergens(contains), may_contain: normaliseAllergens(mayContain) };
}

/** Whether the own ticks differ from the ones loaded (raw lists, K-1): a price-only save sends nothing. */
export function allergenTicksChanged(
    now: { contains: readonly string[]; may_contain: readonly string[] },
    loaded: { contains: readonly string[]; may_contain: readonly string[] },
): boolean {
    return !sameAllergens(now.contains, loaded.contains) || !sameAllergens(now.may_contain, loaded.may_contain);
}

/** Two tick lists hold the same codes. */
export function sameAllergens(a: readonly string[], b: readonly string[]): boolean {
    const left = normaliseAllergens(a);
    const right = normaliseAllergens(b);
    return left.length === right.length && left.every((code, i) => code === right[i]);
}

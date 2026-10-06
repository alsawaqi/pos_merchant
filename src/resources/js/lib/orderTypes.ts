/**
 * LAUNCH packaging add-on — the "Used for" ticks of a stock line (owner
 * decision 1, tester calls 2–3). Pure helpers, node-tested; the server's twin
 * is app/Support/Catalogue/OrderTypes.php.
 *
 *   1 dine in · 2 quick order · 4 to go · 8 delivery; 15 = every type.
 *
 * A line with no tick is refused. The same item may sit on several lines only
 * when their ticks do not overlap (napkin ×1 dine in, napkin ×3 to go and
 * delivery). Copies without ticks mean every type.
 */

export type OrderTypeBucket = 'dine_in' | 'quick' | 'to_go' | 'delivery';

export const ORDER_TYPE_BITS: Record<OrderTypeBucket, number> = {
    dine_in: 1,
    quick: 2,
    to_go: 4,
    delivery: 8,
};

/** The four buckets in the portal's order. */
export const ORDER_TYPE_BUCKETS: OrderTypeBucket[] = ['dine_in', 'quick', 'to_go', 'delivery'];

export const ALL_ORDER_TYPES = 15;

/** A stored / loaded mask: anything outside 1..15 reads as every type. */
export function readMask(mask: unknown): number {
    const n = typeof mask === 'string' && /^\d+$/.test(mask) ? Number(mask) : mask;
    return typeof n === 'number' && Number.isInteger(n) && n >= 1 && n <= ALL_ORDER_TYPES ? n : ALL_ORDER_TYPES;
}

/** Whether a bucket is ticked. */
export function hasType(mask: number, bucket: OrderTypeBucket): boolean {
    return (mask & ORDER_TYPE_BITS[bucket]) !== 0;
}

/** The mask with one bucket switched (may become 0: the line then shows "tick at least one"). */
export function toggleType(mask: number, bucket: OrderTypeBucket): number {
    return mask ^ ORDER_TYPE_BITS[bucket];
}

/** The ticked buckets, in order. */
export function ticked(mask: number): OrderTypeBucket[] {
    return ORDER_TYPE_BUCKETS.filter((b) => hasType(mask, b));
}

/** Whether the line is used for nothing (refused on save). */
export function noTicks(mask: number): boolean {
    return (mask & ALL_ORDER_TYPES) === 0;
}

/**
 * The indexes of lines whose item repeats with overlapping ticks (the server
 * refuses them). `keyOf` names the item ('' = nothing picked yet, never
 * clashes): an ingredient uuid, a product uuid, plus the direction for add-on
 * lines.
 */
export function overlappingLines<T>(lines: T[], keyOf: (line: T) => string, maskOf: (line: T) => number): number[] {
    const used = new Map<string, number>();
    const out: number[] = [];
    lines.forEach((line, index) => {
        const key = keyOf(line);
        if (key === '') return;
        const mask = maskOf(line);
        const before = used.get(key) ?? 0;
        if ((before & mask) !== 0) out.push(index);
        used.set(key, before | mask);
    });
    return out;
}

/**
 * The server's 422 for overlapping ticks (code "order_types_overlap") or for
 * a cooked product with an ingredient on several lines ("cooked_split_lines")
 * in the page's language; null for any other error payload.
 */
export function overlapMessage(payload: unknown, locale: string): string | null {
    if (!payload || typeof payload !== 'object') return null;
    const p = payload as { code?: unknown; message?: unknown; message_ar?: unknown };
    if (p.code !== 'order_types_overlap' && p.code !== 'cooked_split_lines') return null;
    if (locale === 'ar' && typeof p.message_ar === 'string' && p.message_ar !== '') return p.message_ar;
    return typeof p.message === 'string' ? p.message : null;
}

/** Whether any line is for some order types only (the recipe then costs per type). */
export function anyTagged<T>(lines: T[], maskOf: (line: T) => number): boolean {
    return lines.some((l) => readMask(maskOf(l)) !== ALL_ORDER_TYPES);
}

/** The lines used for one bucket (a line with no tick is used for none). */
export function linesFor<T>(lines: T[], maskOf: (line: T) => number, bucket: OrderTypeBucket): T[] {
    return lines.filter((l) => hasType(maskOf(l), bucket));
}

/**
 * LAUNCH packaging add-on — a recipe's cost per order type, once a line is
 * ticked for some types only ("Dine in 0.420 · To go 0.505"); null when every
 * line is for every type.
 */
export function costByType<T>(lines: T[], maskOf: (line: T) => number, costOf: (line: T) => number): Record<OrderTypeBucket, number> | null {
    if (!anyTagged(lines, maskOf)) return null;
    const out = { dine_in: 0, quick: 0, to_go: 0, delivery: 0 } as Record<OrderTypeBucket, number>;
    for (const bucket of ORDER_TYPE_BUCKETS) {
        for (const line of linesFor(lines, maskOf, bucket)) {
            const cost = costOf(line);
            if (Number.isFinite(cost)) out[bucket] += cost;
        }
    }
    return out;
}

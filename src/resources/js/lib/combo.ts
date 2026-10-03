/**
 * LAUNCH-P4 B2 — combo helpers (owner decision 7): a combo has a set price
 * plus choice slots; each slot lets the customer pick between min_choices and
 * max_choices items, and an item may cost extra (the same on every channel).
 * Money is handled in integer baisas (1 OMR = 1000) and shown with 3
 * decimals. No imports, so the node tests can load this file as is.
 */

export interface ComboOptionDraft {
    product_uuid: string;
    extra_price: string | number;
    is_default: boolean;
}

export interface ComboSlotDraft {
    min_choices: number | string;
    max_choices: number | string;
    options: ComboOptionDraft[];
}

/** "1.250" → 1250. Blank or invalid → 0. */
export function toBaisas(value: string | number | null | undefined): number {
    const text = String(value ?? '').trim();
    if (!/^\d+(\.\d{0,3})?$/.test(text)) {
        return 0;
    }
    const [whole, fraction = ''] = text.split('.');
    return Number(whole) * 1000 + Number((fraction + '000').slice(0, 3));
}

/** 1250 → "1.250". */
export function fromBaisas(baisas: number): string {
    const sign = baisas < 0 ? '-' : '';
    const abs = Math.abs(Math.round(baisas));
    return `${sign}${Math.floor(abs / 1000)}.${String(abs % 1000).padStart(3, '0')}`;
}

/**
 * The price range of a combo before the items' own add-ons: the cheapest
 * fill (each slot's minimum number of picks at its cheapest extra) to the
 * dearest (each slot's maximum at its dearest extra). An item may be picked
 * more than once in a slot (the device sends a quantity per item).
 */
export function comboPriceRange(price: string | number, slots: ComboSlotDraft[]): { min: string; max: string } {
    let min = toBaisas(price);
    let max = min;
    for (const slot of slots) {
        const extras = slot.options.map((o) => toBaisas(o.extra_price));
        if (extras.length === 0) continue;
        const least = Math.max(0, Math.trunc(Number(slot.min_choices) || 0));
        const most = Math.max(least, Math.trunc(Number(slot.max_choices) || 0));
        min += least * Math.min(...extras);
        max += most * Math.max(...extras);
    }
    return { min: fromBaisas(min), max: fromBaisas(max) };
}

export type SlotIssue = 'max_below_min' | 'no_options' | 'too_many_defaults' | 'duplicate_item' | 'no_item';

/** What blocks saving a slot (the server checks the same and more). */
export function slotIssues(slot: ComboSlotDraft): SlotIssue[] {
    const issues: SlotIssue[] = [];
    const min = Math.trunc(Number(slot.min_choices) || 0);
    const max = Math.trunc(Number(slot.max_choices) || 0);
    if (max < Math.max(1, min)) issues.push('max_below_min');
    if (slot.options.length === 0) issues.push('no_options');
    if (slot.options.some((o) => o.product_uuid === '')) issues.push('no_item');
    const picked = slot.options.map((o) => o.product_uuid).filter((u) => u !== '');
    if (new Set(picked).size !== picked.length) issues.push('duplicate_item');
    if (slot.options.filter((o) => o.is_default).length > max) issues.push('too_many_defaults');
    return issues;
}

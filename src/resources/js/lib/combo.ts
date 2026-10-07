/**
 * LAUNCH combo add-on (LAUNCH-COMBO_WORK_ORDER.md §1) — combo and meal
 * helpers. A combo or meal is a list of LINES:
 *
 *   included item  a product × quantity, always included; it may offer
 *                  upgrades (another real product at an upgrade price);
 *   choice         "pick N from a category": the whole category by default,
 *                  the merchant unticks items and may give any item an extra
 *                  price; new products of the category join automatically;
 *                  the customer may pick the same item more than once.
 *
 * Money is handled in integer baisas (1 OMR = 1000) and shown with 3
 * decimals. No imports, so the node tests can load this file as is.
 */

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

/** A product the editors can pick (the addon-link-options list). */
export interface PickableItem {
    uuid: string;
    name: string;
    name_ar?: string | null;
    base_price?: string;
    category_id?: number | null;
    status?: string | null;
}

export interface UpgradeDraft {
    product_uuid: string;
    upgrade_price: string;
    /** Fix order 1 (C-12) — the saved product's name and whether it can still be sold. */
    label?: string | null;
    unavailable?: boolean;
}

export interface ItemOverrideDraft {
    excluded: boolean;
    extra_price: string;
}

export interface FixedLineDraft {
    key: string;
    id: number | null;
    kind: 'fixed';
    product_uuid: string;
    quantity: number | string;
    upgrades: UpgradeDraft[];
    /** Fix order 1 (C-12) — the saved product's name and whether it can still be sold. */
    label?: string | null;
    unavailable?: boolean;
}

export interface ChoiceLineDraft {
    key: string;
    id: number | null;
    kind: 'choice';
    name: string;
    name_ar: string;
    category_id: number | null;
    pick_count: number | string;
    /** Keyed by product uuid; a product without an entry is in, free. */
    overrides: Record<string, ItemOverrideDraft>;
}

export type LineDraft = FixedLineDraft | ChoiceLineDraft;

/** The products a choice line offers, in list order, with their extra prices. */
export function choiceItems(line: ChoiceLineDraft, items: PickableItem[]): { item: PickableItem; excluded: boolean; extra: number }[] {
    if (line.category_id === null) return [];
    return items
        .filter((item) => item.category_id === line.category_id)
        .map((item) => {
            const override = line.overrides[item.uuid];
            return { item, excluded: override?.excluded ?? false, extra: toBaisas(override?.extra_price ?? '0') };
        });
}

/**
 * The price range of a combo (or, with price = the meal price, of a meal on
 * top of its main) before the items' own add-ons: the cheapest is the price
 * plus, for every choice, pick N × its cheapest ticked item; the dearest adds
 * every included item's dearest upgrade and pick N × the dearest ticked item.
 */
export function comboPriceRange(price: string | number, lines: LineDraft[], items: PickableItem[]): { min: string; max: string } {
    let min = toBaisas(price);
    let max = min;
    for (const line of lines) {
        if (line.kind === 'fixed') {
            const quantity = Math.max(0, Math.trunc(Number(line.quantity) || 0));
            const upgrades = line.upgrades.filter((u) => u.product_uuid !== '').map((u) => toBaisas(u.upgrade_price));
            if (upgrades.length > 0) max += quantity * Math.max(0, ...upgrades);
            continue;
        }
        const pick = Math.max(0, Math.trunc(Number(line.pick_count) || 0));
        const extras = choiceItems(line, items).filter((row) => !row.excluded).map((row) => row.extra);
        if (extras.length === 0) continue;
        min += pick * Math.min(...extras);
        max += pick * Math.max(...extras);
    }
    return { min: fromBaisas(min), max: fromBaisas(max) };
}

export type LineIssue =
    | 'no_item'
    | 'quantity_range'
    | 'upgrade_no_item'
    | 'upgrade_is_item'
    | 'duplicate_upgrade'
    | 'no_name'
    | 'no_category'
    | 'pick_range'
    | 'nothing_to_pick';

/** What blocks saving a line (the server checks the same and more). */
export function lineIssues(line: LineDraft, items: PickableItem[]): LineIssue[] {
    const issues: LineIssue[] = [];
    if (line.kind === 'fixed') {
        const quantity = Number(line.quantity);
        if (line.product_uuid === '') issues.push('no_item');
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 99) issues.push('quantity_range');
        if (line.upgrades.some((u) => u.product_uuid === '')) issues.push('upgrade_no_item');
        if (line.product_uuid !== '' && line.upgrades.some((u) => u.product_uuid === line.product_uuid)) issues.push('upgrade_is_item');
        const picked = line.upgrades.map((u) => u.product_uuid).filter((u) => u !== '');
        if (new Set(picked).size !== picked.length) issues.push('duplicate_upgrade');
        return issues;
    }
    const pick = Number(line.pick_count);
    if (line.name.trim() === '') issues.push('no_name');
    if (line.category_id === null) issues.push('no_category');
    if (!Number.isInteger(pick) || pick < 1 || pick > 20) issues.push('pick_range');
    if (line.category_id !== null && choiceItems(line, items).every((row) => row.excluded)) issues.push('nothing_to_pick');
    return issues;
}

/** The lines as the server saves them: included items first, then the choices, in the order shown. */
export function linesPayload(lines: LineDraft[], items: PickableItem[]): (
    | { id: number | null; kind: 'fixed'; product_uuid: string; quantity: number; upgrades: { product_uuid: string; upgrade_price: string }[] }
    | { id: number | null; kind: 'choice'; name: string; name_ar: string | null; category_id: number; pick_count: number; items: { product_uuid: string; excluded: boolean; extra_price: string }[] }
)[] {
    const price = (value: string): string => (String(value ?? '').trim() === '' ? '0' : String(value).trim());
    const fixed = lines.filter((l): l is FixedLineDraft => l.kind === 'fixed').map((l) => ({
        id: l.id,
        kind: 'fixed' as const,
        product_uuid: l.product_uuid,
        quantity: Number(l.quantity),
        upgrades: l.upgrades.map((u) => ({ product_uuid: u.product_uuid, upgrade_price: price(u.upgrade_price) })),
    }));
    const choices = lines.filter((l): l is ChoiceLineDraft => l.kind === 'choice').map((l) => ({
        id: l.id,
        kind: 'choice' as const,
        name: l.name.trim(),
        name_ar: l.name_ar.trim() === '' ? null : l.name_ar.trim(),
        category_id: Number(l.category_id),
        pick_count: Number(l.pick_count),
        // Only what changes something: an unticked item or an extra price.
        items: choiceItems(l, items)
            .filter((row) => row.excluded || row.extra > 0)
            .map((row) => ({ product_uuid: row.item.uuid, excluded: row.excluded, extra_price: fromBaisas(row.extra) })),
    }));
    return [...fixed, ...choices];
}

/** The saved lines (GET) → the editor's drafts. */
export function draftsFrom(
    lines: {
        id: number;
        kind: 'fixed' | 'choice';
        product_uuid: string | null;
        product_name?: string | null;
        product_available?: boolean;
        quantity: number | null;
        upgrades: { product_uuid: string | null; upgrade_price: string; product_name?: string | null; product_available?: boolean }[];
        name: string | null;
        name_ar: string | null;
        category_id: number | null;
        pick_count: number | null;
        items: { product_uuid: string | null; excluded: boolean; extra_price: string }[];
    }[],
    nextKey: (prefix: string) => string,
): LineDraft[] {
    return lines.map((line) => {
        if (line.kind === 'fixed') {
            return {
                key: nextKey('line'),
                id: line.id,
                kind: 'fixed' as const,
                product_uuid: line.product_uuid ?? '',
                quantity: line.quantity ?? 1,
                upgrades: line.upgrades.map((u) => ({
                    product_uuid: u.product_uuid ?? '',
                    upgrade_price: u.upgrade_price,
                    label: u.product_name ?? null,
                    unavailable: u.product_available === false,
                })),
                label: line.product_name ?? null,
                unavailable: line.product_available === false,
            };
        }
        const overrides: Record<string, ItemOverrideDraft> = {};
        for (const row of line.items) {
            if (row.product_uuid) overrides[row.product_uuid] = { excluded: row.excluded, extra_price: row.extra_price };
        }
        return {
            key: nextKey('line'),
            id: line.id,
            kind: 'choice' as const,
            name: line.name ?? '',
            name_ar: line.name_ar ?? '',
            category_id: line.category_id,
            pick_count: line.pick_count ?? 1,
            overrides,
        };
    });
}

/**
 * Fix order 1 (C-12) — an included item or upgrade that can no longer be
 * sold: saved as unavailable (deleted / switched off), or missing from the
 * pickable products, or not active there.
 */
export function cannotBeSold(uuid: string, unavailable: boolean | undefined, items: PickableItem[]): boolean {
    if (uuid === '') return false;
    const item = items.find((i) => i.uuid === uuid);
    return unavailable === true || item === undefined || (item.status != null && item.status !== 'active');
}

/**
 * A meal's mains: the products of its categories, each with whether the
 * merchant unticked it (new products of the category join automatically).
 */
export function mealMains(categoryIds: number[], excluded: string[], items: PickableItem[]): { item: PickableItem; ticked: boolean }[] {
    return items
        .filter((item) => item.category_id != null && categoryIds.includes(item.category_id))
        .map((item) => ({ item, ticked: !excluded.includes(item.uuid) }));
}

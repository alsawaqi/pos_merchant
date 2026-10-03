/**
 * LAUNCH item kind — what kind of item an ingredient is (owner decision
 * 2026-10-03), asked instead of a "base unit":
 *
 *   Weighed (rice, cheese, coffee beans) — stored in g, typed in kg or g;
 *   Liquid  (milk, oil, syrup)           — stored in ml, typed in l or ml;
 *   Counted (eggs, cups, buns)           — stored in pieces.
 *
 * Packs, boxes and crates are pack sizes of any kind, not a kind. No data is
 * converted: the kind is read from the stored unit, so an older kg / l / pack
 * / box ingredient shows as Weighed / Liquid / Counted and keeps its unit.
 *
 * Self-contained (no imports) so the node tests can load it on its own.
 */

export type ItemKind = 'weighed' | 'liquid' | 'counted';

export const ITEM_KINDS: ItemKind[] = ['weighed', 'liquid', 'counted'];

/** The unit a NEW ingredient of each kind is stored in. */
export const KIND_STORED_UNIT: Record<ItemKind, 'g' | 'ml' | 'piece'> = {
    weighed: 'g',
    liquid: 'ml',
    counted: 'piece',
};

/** The kind of a stored unit: g/kg weighed, ml/l liquid, piece/pack/box counted. */
export function kindOfUnit(unit: string | null | undefined): ItemKind {
    if (unit === 'g' || unit === 'kg') return 'weighed';
    if (unit === 'ml' || unit === 'l') return 'liquid';
    return 'counted';
}

/** An older stored unit the kind question would not choose (kg, l, pack, box): shown as "stored in kg". */
export function isLegacyStoredUnit(unit: string | null | undefined): boolean {
    return !!unit && unit !== KIND_STORED_UNIT[kindOfUnit(unit)];
}

/** One unit an amount of the item can be typed in, with how many stored units ONE of it holds. */
export interface KindUnit {
    value: string;
    factor: number;
}

const METRIC_SIZE: Record<string, number> = { kg: 1000, g: 1, l: 1000, ml: 1 };

/**
 * The units of the item's kind, big first, each with its size in the stored
 * unit (mirrors IngredientUnit::kindUnits / factorOf on the server):
 * stored g → kg 1000, g 1; stored kg → kg 1, g 0.001; counted → its own unit.
 */
export function kindUnits(storedUnit: string | null | undefined): KindUnit[] {
    if (!storedUnit) return [];
    const kind = kindOfUnit(storedUnit);
    if (kind === 'counted') return [{ value: storedUnit, factor: 1 }];
    const pair = kind === 'weighed' ? ['kg', 'g'] : ['l', 'ml'];
    return pair.map((value) => ({ value, factor: METRIC_SIZE[value]! / METRIC_SIZE[storedUnit]! }));
}

function round4(value: number): number {
    return Math.round((value + Number.EPSILON * Math.sign(value)) * 10000) / 10000;
}

/** Up to 4 decimals, trailing zeros trimmed ("24", "1.5", "0.0003"); never "-0". */
export function trimAmount(value: number): string {
    if (!Number.isFinite(value)) return '';
    const text = round4(value).toFixed(4).replace(/\.?0+$/, '');
    return text === '-0' ? '0' : text;
}

/**
 * An amount as people read it: 1000 g or ml and above in kg or l ("24 l",
 * not "24000.000 ml"); below 1000 it stays in g or ml. Up to 4 decimals,
 * trailing zeros trimmed. Any other unit keeps its unit.
 */
export function friendlyAmount(quantity: string | number | null | undefined, unit: string | null | undefined): { amount: string; unit: string } {
    const u = unit ?? '';
    const n = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? ''));
    if (!Number.isFinite(n)) return { amount: String(quantity ?? ''), unit: u };
    if ((u === 'g' || u === 'ml') && Math.abs(n) >= 1000) {
        return { amount: trimAmount(n / 1000), unit: u === 'g' ? 'kg' : 'l' };
    }
    return { amount: trimAmount(n), unit: u };
}

/**
 * What a stored amount reopens as in a "holds [amount] [unit]" input: the
 * friendly unit when it converts back exactly at 4 decimals (12000 ml →
 * 12 l), else the stored unit (1234.5678 ml stays ml), so saving an
 * untouched row never changes it.
 */
export function holdsEntry(quantity: string | number | null | undefined, storedUnit: string | null | undefined): { amount: string; unit: string } {
    const n = typeof quantity === 'number' ? quantity : parseFloat(String(quantity ?? ''));
    const unit = storedUnit ?? '';
    if (!Number.isFinite(n) || n <= 0) return { amount: '', unit };
    const friendly = friendlyAmount(n, unit);
    const back = kindUnits(unit).find((u) => u.value === friendly.unit)?.factor ?? 1;
    if (friendly.unit !== unit && Math.abs(parseFloat(friendly.amount) * back - n) > 1e-9) {
        return { amount: trimAmount(n), unit };
    }
    return friendly;
}

/**
 * An amount typed in one of the kind's units, in the stored unit (4
 * decimals) — null when it is not a positive number or the unit is not of
 * the kind. Used where the portal converts itself (count container, prep
 * yield, minimum stock); pack sizes are converted by the server.
 */
export function toStoredAmount(amount: string | number | null | undefined, unit: string, storedUnit: string | null | undefined): number | null {
    const n = typeof amount === 'number' ? amount : parseFloat(String(amount ?? '').trim());
    const factor = kindUnits(storedUnit).find((u) => u.value === unit)?.factor;
    if (!Number.isFinite(n) || n <= 0 || factor === undefined) return null;
    const stored = round4(n * factor);
    return stored > 0 ? stored : null;
}

/**
 * F1 — the unit a cost is typed and shown per: kg for a Weighed item, l for
 * a Liquid one (whatever it is stored in), else its own count unit.
 */
export function costUnit(storedUnit: string | null | undefined): string {
    const kind = kindOfUnit(storedUnit);
    if (kind === 'weighed') return 'kg';
    if (kind === 'liquid') return 'l';
    return storedUnit ?? '';
}

/** A cost (OMR) with at least 3 decimals and at most 6 ("0.150", "0.00035", "1.2345"). */
export function formatCost(value: number): string {
    if (!Number.isFinite(value)) return '';
    const fixed = (Math.round((value + Number.EPSILON * Math.sign(value)) * 1e6) / 1e6).toFixed(6);
    const [whole, decimals = ''] = fixed.split('.');
    const trimmed = decimals.replace(/0+$/, '');
    const text = `${whole}.${trimmed.padEnd(3, '0')}`;
    return text.startsWith('-') && parseFloat(text) === 0 ? text.slice(1) : text;
}

/**
 * F1 — a cost per STORED unit as people read it: per kg or per l for a g /
 * ml item ("0.150 OMR / l", not "0.00015 OMR / ml"); a counted item keeps its
 * own unit.
 */
export function friendlyCost(costPerStored: string | number | null | undefined, storedUnit: string | null | undefined): { amount: string; unit: string } {
    const n = typeof costPerStored === 'number' ? costPerStored : parseFloat(String(costPerStored ?? ''));
    const unit = costUnit(storedUnit);
    if (!Number.isFinite(n)) return { amount: String(costPerStored ?? ''), unit };
    const factor = kindUnits(storedUnit).find((u) => u.value === unit)?.factor ?? 1;
    return { amount: formatCost(n * factor), unit };
}

/**
 * F1 — a cost typed per one of the kind's units (per kg, per l …), as the
 * cost per STORED unit the server keeps (6 decimals): 0.150 per l of a ml
 * item → 0.00015. Null when it is not a number ≥ 0 or the unit is not of the kind.
 */
export function toStoredCost(cost: string | number | null | undefined, unit: string, storedUnit: string | null | undefined): number | null {
    const n = typeof cost === 'number' ? cost : parseFloat(String(cost ?? '').trim());
    const factor = kindUnits(storedUnit).find((u) => u.value === unit)?.factor;
    if (!Number.isFinite(n) || n < 0 || factor === undefined) return null;
    return Math.round((n / factor + Number.EPSILON) * 1e6) / 1e6;
}

/** The token that names an ingredient's count container (its piece unit) on the wire. */
export const PIECE_UNIT = '@piece';

/** What the amount pickers need from an ingredient (a subset of the API shape). */
export interface EntryUnitSource {
    unit: string;
    alt_units?: { name: string; factor: string }[];
    auto_units?: { name: string; factor: string }[];
    piece_unit_label?: string | null;
    piece_unit_label_ar?: string | null;
    units_per_piece?: string | null;
}

export interface EntryUnitOption {
    /** '' = the stored unit; otherwise the unit name (or '@piece') sent to the server. */
    value: string;
    label: string;
    /** Stored units in ONE of this unit. */
    factor: number;
}

function positiveNumber(value: string | null | undefined): number | null {
    const n = parseFloat(String(value ?? ''));
    return Number.isFinite(n) && n > 0 ? n : null;
}

/**
 * A7 — every unit an amount of this ingredient can be typed in (waste,
 * adjust, transfers, restock requests, counts): the stored unit, its pack
 * sizes ("crate (12 l)"), the other unit of its kind (kg ↔ g, l ↔ ml) and its
 * count container ("bottle (1.5 l)"). A pack size wins over a metric unit of
 * the same name, as on the server (IngredientUnitConverter).
 */
export function entryUnitOptions(ingredient: EntryUnitSource | null | undefined, locale?: string | null): EntryUnitOption[] {
    if (!ingredient) return [];
    const base = ingredient.unit;
    const holds = (factor: number): string => {
        const friendly = friendlyAmount(factor, base);
        return `${friendly.amount} ${friendly.unit}`;
    };
    const options: EntryUnitOption[] = [{ value: '', label: base, factor: 1 }];
    const seen = new Set<string>([base]);
    for (const pack of ingredient.alt_units ?? []) {
        const factor = positiveNumber(pack.factor);
        if (seen.has(pack.name) || factor === null) continue;
        seen.add(pack.name);
        options.push({ value: pack.name, label: `${pack.name} (${holds(factor)})`, factor });
    }
    for (const auto of ingredient.auto_units ?? []) {
        const factor = positiveNumber(auto.factor);
        if (seen.has(auto.name) || factor === null) continue;
        seen.add(auto.name);
        options.push({ value: auto.name, label: auto.name, factor });
    }
    const perPiece = positiveNumber(ingredient.units_per_piece);
    if (ingredient.piece_unit_label && perPiece !== null && !(base === 'piece' && perPiece === 1)) {
        const label = locale === 'ar' && ingredient.piece_unit_label_ar ? ingredient.piece_unit_label_ar : ingredient.piece_unit_label;
        options.push({ value: PIECE_UNIT, label: `${label} (${holds(perPiece)})`, factor: perPiece });
    }
    return options;
}

/** Stored units per one of the selected unit (1 when unknown — the server re-validates). */
export function entryUnitFactor(ingredient: EntryUnitSource | null | undefined, value: string): number {
    if (!ingredient || value.trim() === '' || value === ingredient.unit) return 1;
    return entryUnitOptions(ingredient).find((o) => o.value === value)?.factor ?? 1;
}

/** The stored unit for a kind choice: the item's own unit when it is already of that kind, else g / ml / piece. */
export function storedUnitForKind(kind: ItemKind, currentUnit?: string | null): string {
    if (currentUnit && kindOfUnit(currentUnit) === kind) return currentUnit;
    return KIND_STORED_UNIT[kind];
}

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

/** The stored unit for a kind choice: the item's own unit when it is already of that kind, else g / ml / piece. */
export function storedUnitForKind(kind: ItemKind, currentUnit?: string | null): string {
    if (currentUnit && kindOfUnit(currentUnit) === kind) return currentUnit;
    return KIND_STORED_UNIT[kind];
}

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

/** The stored unit for a kind choice: the item's own unit when it is already of that kind, else g / ml / piece. */
export function storedUnitForKind(kind: ItemKind, currentUnit?: string | null): string {
    if (currentUnit && kindOfUnit(currentUnit) === kind) return currentUnit;
    return KIND_STORED_UNIT[kind];
}

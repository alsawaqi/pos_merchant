/**
 * LAUNCH review add-on (F) + fix order B-1 (M5, L7/L8, L10, T1) — what a
 * SCAN does on each screen, as pure helpers the screens call (and the node
 * tests run): Purchases, Transfers, Counts, Waste, Restock requests and the
 * three list searches.
 *
 * The rules:
 *   - the same scan again makes it 2: a container / pack code adds one of it
 *     to its line; an item-level code (an SKU, an item barcode, a code linked
 *     without a container) adds one to the line's container (or amount);
 *   - typed work is never dropped silently: a typed amount in a container
 *     unit becomes that container's row, a typed total in kg / l stays as the
 *     (lowered) total, and a line that holds one container only refuses a
 *     second container with a reason instead of replacing it;
 *   - Purchases never takes an item it would refuse (a prep item, a cooked /
 *     made-to-order / combo product): the reason is returned to show.
 */

import { findContainer, isContainerToken, plusOne, scanTargetIndex, type ContainerHolder } from '@/lib/containers';

/** What the scan endpoint returned (the parts the screens use). */
export interface ScanHit {
    found?: boolean;
    item_type?: 'ingredient' | 'physical' | 'product';
    item?: { uuid: string; name?: string };
    container?: { uuid: string; display_name?: string } | null;
    pack?: { uuid: string; display_name?: string } | null;
    purchasable?: boolean;
    not_purchasable_reason?: string | null;
}

export type ScanRefusal = 'not_listed' | 'not_purchasable_prep' | 'not_purchasable' | 'one_container' | 'other_item';

export type ScanOutcome = { ok: true; index: number } | { ok: false; reason: ScanRefusal; index?: number };

/** One item's amount on a document line: container rows, and a typed amount in a unit. */
export interface AmountState {
    containers: { container_uuid: string; pieces: string | number }[];
    amount: string | number;
    unit: string;
}

const typed = (value: string | number | null | undefined): boolean => String(value ?? '').trim() !== '';

/** The container a unit value names ('' / a kind unit = none): a token, a uuid, or '@piece' (the count container). */
export function unitContainer(holder: ContainerHolder | null | undefined, unit: string): string | null {
    if (!unit) return null;
    if (unit === '@piece') return holder?.count_container_uuid ?? null;
    if (!isContainerToken(unit) && !(holder?.alt_units ?? []).some((c) => c.uuid === unit)) return null;
    return findContainer(holder, unit)?.uuid ?? null;
}

/**
 * One more of a scanned container on a line (returns the new state, or why
 * not). `single` = the line holds one container only (waste, restock).
 */
export function addContainerScan(
    state: AmountState,
    containerUuid: string,
    holder: ContainerHolder | null | undefined,
    single: boolean,
): { ok: true; state: AmountState } | { ok: false; reason: 'one_container' } {
    const rows = state.containers.map((r) => ({ ...r }));
    const same = rows.find((r) => r.container_uuid === containerUuid);
    if (same) {
        same.pieces = plusOne(same.pieces);
        return { ok: true, state: { ...state, containers: rows } };
    }
    const filled = rows.filter((r) => r.container_uuid !== '' && typed(r.pieces));
    if (single && filled.length > 0) return { ok: false, reason: 'one_container' };

    if (filled.length === 0 && typed(state.amount)) {
        const asContainer = unitContainer(holder, state.unit);
        if (asContainer !== null) {
            // "3" typed in bottles: those 3 bottles become the row (never lost).
            if (asContainer === containerUuid) {
                return { ok: true, state: { containers: [{ container_uuid: containerUuid, pieces: plusOne(state.amount) }], amount: '', unit: '' } };
            }
            if (single) return { ok: false, reason: 'one_container' };
            return { ok: true, state: { containers: [{ container_uuid: asContainer, pieces: String(state.amount) }, { container_uuid: containerUuid, pieces: '1' }], amount: '', unit: '' } };
        }
        // A total typed in kg / l stays as the total (it may be lower than the containers hold).
        return { ok: true, state: { containers: [{ container_uuid: containerUuid, pieces: '1' }], amount: state.amount, unit: state.unit } };
    }

    const kept = single ? [] : rows.filter((r) => r.container_uuid !== '' && typed(r.pieces));
    return { ok: true, state: { ...state, containers: [...kept, { container_uuid: containerUuid, pieces: '1' }] } };
}

/**
 * The line a scanned item goes on: its own line, else the first blank line
 * (given the item), else a new line pushed at the end. Returns its index.
 */
export function pickScanLine(lines: { ingredient_uuid: string }[], itemUuid: string, blank: () => { ingredient_uuid: string }): number {
    const own = scanTargetIndex(lines, (l) => l.ingredient_uuid === itemUuid);
    if (own >= 0) return own;
    const empty = scanTargetIndex(lines, (l) => l.ingredient_uuid === '');
    if (empty >= 0) {
        lines[empty]!.ingredient_uuid = itemUuid;
        return empty;
    }
    lines.push({ ...blank(), ingredient_uuid: itemUuid });
    return lines.length - 1;
}

/** A multi-line document line (transfer, restock request). */
export interface DocLine {
    ingredient_uuid: string;
    quantity: string | number;
    unit: string;
    containers: { container_uuid: string; pieces: string | number }[];
}

/**
 * A scan on a transfer (several containers per line) or a restock request
 * (one container per line): the item's line, one more of the container.
 */
export function applyLineScan(
    lines: DocLine[],
    hit: ScanHit,
    holderOf: (uuid: string) => ContainerHolder | null,
    blank: () => DocLine,
    single: boolean,
): ScanOutcome {
    const uuid = hit.item?.uuid ?? '';
    const holder = holderOf(uuid);
    if (!uuid || holder === null) return { ok: false, reason: 'not_listed' };
    const index = pickScanLine(lines, uuid, blank);
    const containerUuid = hit.container?.uuid;
    if (!containerUuid) return { ok: true, index };
    const line = lines[index]!;
    const next = addContainerScan({ containers: line.containers, amount: line.quantity, unit: line.unit }, containerUuid, holder, single);
    if (!next.ok) return { ok: false, reason: next.reason, index };
    line.containers = next.state.containers;
    line.quantity = next.state.amount;
    line.unit = next.state.unit;
    return { ok: true, index };
}

/** A day-end count row (blind: a scan only adds what was scanned). */
export interface CountScanRow {
    ingredient: ContainerHolder & { uuid: string };
    counted: string | number;
    unit: string;
    containers: { container_uuid: string; pieces: string | number }[];
}

/** A scan on the day-end count: the item's row, one more of the container; a typed count is kept. */
export function applyCountScan(rows: CountScanRow[], hit: ScanHit): ScanOutcome {
    const index = scanTargetIndex(rows, (r) => r.ingredient.uuid === hit.item?.uuid);
    if (index < 0) return { ok: false, reason: 'not_listed' };
    const containerUuid = hit.container?.uuid;
    if (!containerUuid) return { ok: true, index };
    const row = rows[index]!;
    const next = addContainerScan({ containers: row.containers, amount: row.counted, unit: row.unit }, containerUuid, row.ingredient, false);
    if (!next.ok) return { ok: false, reason: next.reason, index };
    row.containers = next.state.containers;
    row.counted = next.state.amount;
    row.unit = next.state.unit;
    return { ok: true, index };
}

/** The waste form: one item, one container. */
export interface WasteScanForm {
    ingredient_uuid: string;
    quantity: string | number;
    unit: string;
    containers: { container_uuid: string; pieces: string | number }[];
}

/** A scan on the waste form: picks the item (never over another item's typed waste), one more of the container. */
export function applyWasteScan(form: WasteScanForm, hit: ScanHit, holderOf: (uuid: string) => ContainerHolder | null): ScanOutcome {
    const uuid = hit.item?.uuid ?? '';
    const holder = holderOf(uuid);
    if (!uuid || holder === null) return { ok: false, reason: 'not_listed' };
    if (form.ingredient_uuid !== '' && form.ingredient_uuid !== uuid && (typed(form.quantity) || form.containers.length > 0)) {
        return { ok: false, reason: 'other_item' };
    }
    if (form.ingredient_uuid !== uuid) {
        form.ingredient_uuid = uuid;
        form.quantity = '';
        form.unit = '';
        form.containers = [];
    }
    const containerUuid = hit.container?.uuid;
    if (!containerUuid) return { ok: true, index: 0 };
    const next = addContainerScan({ containers: form.containers, amount: form.quantity, unit: form.unit }, containerUuid, holder, true);
    if (!next.ok) return { ok: false, reason: next.reason, index: 0 };
    form.containers = next.state.containers;
    form.quantity = next.state.amount;
    form.unit = next.state.unit;
    return { ok: true, index: 0 };
}

/** A Purchases line (the parts a scan touches). */
export interface PurchaseScanLine {
    itemKey: string;
    container_uuid: string;
    pieces: string | number;
    amount: string | number;
}

/** What Purchases lends the scan: a new blank line, and its "item picked" reset (first container). */
export interface PurchaseScanDeps {
    blankLine(): PurchaseScanLine;
    onItemChange(line: PurchaseScanLine): void;
}

/**
 * A scan on Purchases. The same scan again makes it 2 — a container / pack
 * code on its line, and an item-level code on the item's line (one more of
 * its container, or one more piece). A prep item or a product Purchases
 * refuses is never added.
 */
export function applyPurchaseScan(
    lines: PurchaseScanLine[],
    hit: ScanHit,
    deps: PurchaseScanDeps,
): ScanOutcome {
    const uuid = hit.item?.uuid;
    if (!uuid || !hit.item_type) return { ok: false, reason: 'not_listed' };
    if (hit.purchasable === false) {
        return { ok: false, reason: hit.not_purchasable_reason === 'prep' ? 'not_purchasable_prep' : 'not_purchasable' };
    }
    const key = `${hit.item_type}:${uuid}`;
    const containerUuid = hit.container?.uuid ?? hit.pack?.uuid ?? '';

    const existing = containerUuid !== ''
        ? scanTargetIndex(lines, (l) => l.itemKey === key && l.container_uuid === containerUuid)
        : scanTargetIndex(lines, (l) => l.itemKey === key);
    if (existing >= 0) {
        const line = lines[existing]!;
        if (line.container_uuid !== '') line.pieces = plusOne(line.pieces);
        else line.amount = plusOne(line.amount);
        return { ok: true, index: existing };
    }

    let index = scanTargetIndex(lines, (l) => l.itemKey === '');
    if (index < 0) {
        lines.push(deps.blankLine());
        index = lines.length - 1;
    }
    const line = lines[index]!;
    line.itemKey = key;
    deps.onItemChange(line);
    if (containerUuid !== '') line.container_uuid = containerUuid;
    if (line.container_uuid !== '') line.pieces = '1';
    else if (hit.item_type !== 'ingredient') line.amount = '1';
    return { ok: true, index };
}

/** A list search: the scanned item in the list, or null. */
export function findScanned<T extends { uuid: string }>(list: T[], hit: ScanHit): T | null {
    const uuid = hit.item?.uuid;
    return uuid ? (list.find((i) => i.uuid === uuid) ?? null) : null;
}

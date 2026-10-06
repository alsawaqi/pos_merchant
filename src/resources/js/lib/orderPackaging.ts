/**
 * LAUNCH packaging add-on — the per-order packaging editor's pure helpers
 * (Inventory → Order packaging; node-tested). One list per order type; each
 * line an ingredient (a unit of its kind: base, metric pair, a container) or
 * a physical item (pieces, or one of its packs, named by the pack's token).
 * The server ({@link \App\Actions\Pos\Inventory\SaveOrderPackagingAction})
 * converts and is the authority.
 */

import { containerToken, plusOne } from '@/lib/containers';
import type { OrderPackagingItem, OrderPackagingLine, OrderPackagingLinePayload } from '@/lib/api/orderPackaging';

/** One line as the editor holds it. */
export interface PackagingDraft {
    type: 'ingredient' | 'product';
    ingredient_uuid: string;
    product_uuid: string;
    quantity: string;
    /** An ingredient's unit ('' = its base) or a physical item's pack token ('' = pieces). */
    unit: string;
}

/** A stored line → the editor: as it was typed while that still holds, else the base / pieces. */
export function draftOf(line: OrderPackagingLine): PackagingDraft {
    const typed = line.entered_unit && line.entered_quantity;
    return {
        type: line.type,
        ingredient_uuid: line.ingredient_uuid ?? '',
        product_uuid: line.product_uuid ?? '',
        quantity: trimNumber(typed ? String(line.entered_quantity) : line.quantity),
        unit: typed ? String(line.entered_unit) : '',
    };
}

/** A blank line of a kind. */
export function blankDraft(type: 'ingredient' | 'product' = 'ingredient'): PackagingDraft {
    return { type, ingredient_uuid: '', product_uuid: '', quantity: '1', unit: '' };
}

function refOf(draft: PackagingDraft): string {
    const uuid = draft.type === 'ingredient' ? draft.ingredient_uuid : draft.product_uuid;
    return uuid === '' ? '' : `${draft.type}:${uuid}`;
}

/** The lines a save sends: every line with an item picked. */
export function payloadOf(drafts: PackagingDraft[]): OrderPackagingLinePayload[] {
    return drafts.filter((d) => refOf(d) !== '').map((d) => ({
        type: d.type,
        ingredient_uuid: d.type === 'ingredient' ? d.ingredient_uuid : null,
        product_uuid: d.type === 'product' ? d.product_uuid : null,
        quantity: String(d.quantity).trim(),
        unit: d.unit === '' ? null : d.unit,
    }));
}

/** Indexes of lines naming an item already on the list (one line per item: the server refuses a second). */
export function duplicateLines(drafts: PackagingDraft[]): number[] {
    const seen = new Set<string>();
    const out: number[] = [];
    drafts.forEach((d, i) => {
        const ref = refOf(d);
        if (ref === '') return;
        if (seen.has(ref)) out.push(i);
        seen.add(ref);
    });
    return out;
}

/** A picked line whose amount is blank, not a number or not above 0. */
export function amountMissing(draft: PackagingDraft): boolean {
    if (refOf(draft) === '') return false;
    const n = Number(String(draft.quantity).trim());
    return String(draft.quantity).trim() === '' || !Number.isFinite(n) || n <= 0;
}

/** What the scan box found (the parts used here). */
export interface PackagingScanHit {
    found?: boolean;
    item_type?: 'ingredient' | 'physical' | 'product';
    item?: { uuid: string; is_prep?: boolean; stock_mode?: string };
    container?: { uuid: string; token?: string } | null;
    pack?: { uuid: string } | null;
}

export type PackagingScanRefusal = 'not_found' | 'prep' | 'not_pieces' | 'other_unit';

/**
 * A scan adds the item: the same code again makes it 2 (a container / pack
 * code counts that container / pack). A prep item is never packaging; a
 * product not counted in pieces is refused; a line of the item in another
 * unit is not changed (the reason is shown instead).
 */
export function applyPackagingScan(
    drafts: PackagingDraft[],
    hit: PackagingScanHit,
): { ok: true; drafts: PackagingDraft[]; index: number } | { ok: false; reason: PackagingScanRefusal } {
    if (!hit.found || !hit.item) return { ok: false, reason: 'not_found' };
    const type: 'ingredient' | 'product' = hit.item_type === 'ingredient' ? 'ingredient' : 'product';
    if (type === 'ingredient' && hit.item.is_prep) return { ok: false, reason: 'prep' };
    if (type === 'product' && hit.item.stock_mode !== undefined && hit.item.stock_mode !== 'unit') return { ok: false, reason: 'not_pieces' };

    const unit = type === 'ingredient'
        ? (hit.container ? (hit.container.token ?? containerToken(hit.container.uuid)) : '')
        : (hit.pack ? containerToken(hit.pack.uuid) : '');
    const next = drafts.map((d) => ({ ...d }));
    const index = next.findIndex((d) => d.type === type && (type === 'ingredient' ? d.ingredient_uuid : d.product_uuid) === hit.item!.uuid);
    if (index >= 0) {
        const line = next[index]!;
        if (line.unit !== unit) return { ok: false, reason: 'other_unit' };
        line.quantity = plusOne(line.quantity);
        return { ok: true, drafts: next, index };
    }
    // A blank line is reused before a new one is added.
    const blank = next.findIndex((d) => refOf(d) === '');
    const line: PackagingDraft = {
        type,
        ingredient_uuid: type === 'ingredient' ? hit.item.uuid : '',
        product_uuid: type === 'product' ? hit.item.uuid : '',
        quantity: '1',
        unit,
    };
    if (blank >= 0) {
        next[blank] = line;
        return { ok: true, drafts: next, index: blank };
    }
    next.push(line);
    return { ok: true, drafts: next, index: next.length - 1 };
}

/** A physical item's units: pieces, or one of its packs (by the pack's token). */
export function packOptions(item: OrderPackagingItem | undefined, locale: string, piecesLabel: string): { value: string; label: string }[] {
    return [
        { value: '', label: piecesLabel },
        ...(item?.packs ?? []).map((p) => ({ value: p.token, label: locale === 'ar' ? p.display_name_ar : p.display_name })),
    ];
}

/** Pieces a physical-item line takes (N packs × the pack's pieces, or the pieces typed). */
export function piecesOf(draft: PackagingDraft, item: OrderPackagingItem | undefined): number {
    const n = Number(draft.quantity);
    if (draft.unit === '') return n;
    const pack = (item?.packs ?? []).find((p) => p.token === draft.unit);
    return pack ? n * Number(pack.pieces) : n;
}

/** E2 — a portion warning reworded for one order ("One order would use 200 l…"). */
export function orderWarning<T extends { key: string; params: Record<string, string> }>(warning: T | null): T | null {
    return warning === null ? null : { ...warning, key: warning.key.replace('amount_safety.warnings.', 'order_packaging.warnings.') };
}

/**
 * A read-only line as two parts, so only the amount is isolated left-to-right
 * (review B, L6): ingredient lines in the unit they were typed in, physical
 * items as "N ×".
 */
export function readonlyParts(
    line: OrderPackagingLine,
    locale: string,
    ingredientAmount: (draft: PackagingDraft) => string,
): { amount: string; name: string } {
    const name = locale === 'ar' && line.name_ar ? line.name_ar : (line.name ?? '');
    if (line.type === 'ingredient') return { amount: ingredientAmount(draftOf(line)), name };
    return { amount: `${trimNumber(line.quantity)} ×`, name };
}

function trimNumber(value: string | number | null | undefined): string {
    const text = String(value ?? '').trim();
    if (!text.includes('.')) return text;
    return text.replace(/0+$/, '').replace(/\.$/, '');
}

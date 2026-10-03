/**
 * LAUNCH-P4 M6 — a shelf count below zero is allowed (sell but warn, P2) but
 * must be visible: the stock dialog, the branch page (cooked products too)
 * and the catalogue list show a red "below zero" badge. No imports, so the
 * node tests can load this file as is.
 */

/** True when a decimal stock string / number is under zero. */
export function isBelowZero(qty: string | number | null | undefined): boolean {
    if (qty === null || qty === undefined || qty === '') return false;
    const n = Number(qty);
    return Number.isFinite(n) && n < 0;
}

/**
 * The branches where a piece-counted product (ready-made or cooked) has a
 * shelf count below zero.
 */
export function belowZeroBranchIds(product: {
    stock_mode?: string | null;
    branches?: { branch_id: number; stock_qty: number | string | null }[];
}): number[] {
    if (product.stock_mode !== 'unit' && product.stock_mode !== 'cooked') return [];
    return (product.branches ?? []).filter((b) => isBelowZero(b.stock_qty)).map((b) => b.branch_id);
}

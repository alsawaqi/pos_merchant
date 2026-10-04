/**
 * LAUNCH-P5 B1 — pure helpers for the staff permissions page (no Vue, so the
 * node tests can load them). The server resolves and validates; these only
 * shape the editor.
 */

/** The 19 tick-list action keys, in the shared fixture's order. */
export const TICK_LIST_ACTIONS = [
    'order.void_unpaid', 'order.void_paid', 'table.cancel_line', 'table.cancel_bill',
    'discount.manual', 'comp', 'gift', 'loyalty.redeem', 'sold_out.toggle',
    'receipt.reprint', 'kitchen.reprint', 'reports.view', 'kitchen.screen',
    'shift.close_other', 'payout', 'stock.waste', 'stock.count', 'training.use', 'approvals.give',
];

export interface PositionRow {
    actions: Record<string, boolean>;
    discount_max_percent: number;
}

export type PositionMatrix = Record<string, PositionRow>;

export type PositionMatrixChanges = Record<string, { actions?: Record<string, boolean>; discount_max_percent?: number }>;

/** The i18n key fragment of an action key ('order.void_unpaid' → 'order_void_unpaid'). */
export function actionKey(action: string): string {
    return action.replace(/\./g, '_');
}

/** A cell that is always ticked and cannot be changed (kitchen → kitchen screen). */
export function isAlwaysOn(alwaysOn: Record<string, string[]>, position: string, action: string): boolean {
    return (alwaysOn[position] ?? []).includes(action);
}

/** A deep copy, so the editor never mutates the saved matrix. */
export function cloneMatrix(matrix: PositionMatrix): PositionMatrix {
    return JSON.parse(JSON.stringify(matrix)) as PositionMatrix;
}

/** The positions that may approve other people's actions. */
export function approverPositions(matrix: PositionMatrix): string[] {
    return Object.keys(matrix).filter((p) => matrix[p]?.actions['approvals.give'] === true);
}

/** A whole number from 0 to 100. */
export function validLimit(value: unknown): boolean {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 100;
}

/** Only the cells of `draft` that differ from `saved` — the PUT payload. */
export function diffMatrix(saved: PositionMatrix, draft: PositionMatrix): PositionMatrixChanges {
    const changes: PositionMatrixChanges = {};
    for (const position of Object.keys(draft)) {
        const before = saved[position];
        const after = draft[position];
        if (!before || !after) continue;
        const row: { actions?: Record<string, boolean>; discount_max_percent?: number } = {};
        for (const action of Object.keys(after.actions)) {
            if (before.actions[action] !== after.actions[action]) {
                row.actions = { ...(row.actions ?? {}), [action]: after.actions[action] === true };
            }
        }
        if (before.discount_max_percent !== after.discount_max_percent) {
            row.discount_max_percent = after.discount_max_percent;
        }
        if (row.actions || row.discount_max_percent !== undefined) {
            changes[position] = row;
        }
    }
    return changes;
}

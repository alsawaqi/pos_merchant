/**
 * LAUNCH-P5 B3 — pure helpers for the Approvals report (no Vue, so the node
 * tests can load them).
 */

/** The results the report highlights. */
export const PROBLEM_RESULTS = ['failed', 'missing', 'unverifiable'];

export const ALL_RESULTS = ['verified', 'position_ok', 'failed', 'missing', 'unverifiable', 'legacy'];

/** The six online PIN-checked actions (not on the tick list). */
export const ONLINE_ACTIONS = ['card.reverse', 'production.cancel', 'disposition', 'qr.payment_review', 'qr.expired_cancel', 'bill.combine'];

export function isProblem(result: string): boolean {
    return PROBLEM_RESULTS.includes(result);
}

/** Badge colours: problems red, checked green, legacy grey. */
export function resultBadgeClass(result: string): string {
    if (isProblem(result)) return 'bg-rose-100 text-rose-700';
    if (result === 'verified' || result === 'position_ok') return 'bg-emerald-50 text-emerald-700';
    return 'bg-slate-100 text-slate-600';
}

/**
 * The i18n key of an action's name: tick-list actions reuse the staff
 * permissions page's names, the online actions have their own; anything else
 * (a future key) has none and is shown as it is.
 */
export function actionLabelKey(action: string, tickListActions: string[]): string | null {
    const key = action.replace(/\./g, '_');
    if (tickListActions.includes(action)) return `settings.staff_permissions.actions.${key}.label`;
    if (ONLINE_ACTIONS.includes(action)) return `reports.approvals.online_actions.${key}`;
    return null;
}

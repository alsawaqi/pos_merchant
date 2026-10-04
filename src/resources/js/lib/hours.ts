/**
 * LAUNCH-P5 B4 — pure helpers for the Hours report (no Vue, so the node
 * tests can load them). Times on this page are Muscat-local "Y-m-d H:i" as
 * the server sends them; the browser's own time zone is never used.
 */

/** "2026-10-05 09:00" → "2026-10-05T09:00" (a datetime-local input value). */
export function toInputValue(local: string | null): string {
    return local ? local.replace(' ', 'T') : '';
}

/** "2026-10-05T09:00" → "2026-10-05 09:00" (what the server expects); '' → null. */
export function fromInputValue(value: string): string | null {
    const v = value.trim();
    return v === '' ? null : v.replace('T', ' ').slice(0, 16);
}

/** The time part of a local "Y-m-d H:i" ("09:00"). */
export function timeOf(local: string | null): string {
    return local ? local.slice(11, 16) : '—';
}

/** Hours with two decimals, or a dash. */
export function formatHours(hours: number | null | undefined): string {
    return typeof hours === 'number' && Number.isFinite(hours) ? hours.toFixed(2) : '—';
}

/** Row highlight: a missing clock-out is the problem the page points at. */
export function hoursRowClass(row: { no_clock_out: boolean; open: boolean }): string {
    if (row.no_clock_out) return 'bg-amber-50';
    if (row.open) return 'bg-emerald-50/50';
    return '';
}

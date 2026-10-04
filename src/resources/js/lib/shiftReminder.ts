/**
 * LAUNCH-P5 B6 — the branch's shift-end reminder time (pure helpers, no Vue,
 * so the node tests can load them). The server stores "HH:MM" (Muscat time)
 * or null = off; a blank field means off.
 */

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

/** A time input's value ("21:30" or "21:30:00") as the "HH:MM" to save; blank → null; invalid → undefined. */
export function normalizeReminder(value: string | null | undefined): string | null | undefined {
    const v = (value ?? '').trim();
    if (v === '') return null;
    const hhmm = v.slice(0, 5);
    return HHMM.test(hhmm) && (v.length === 5 || /^:\d\d$/.test(v.slice(5))) ? hhmm : undefined;
}

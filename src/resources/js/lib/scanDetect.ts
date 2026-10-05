/**
 * LAUNCH review add-on (F, tester call step 11) — tell a barcode SCANNER from
 * a person typing. A USB / Bluetooth scanner "types" the whole code in a burst
 * of keystrokes a few milliseconds apart and ends with Enter; a person types
 * slower and unevenly.
 *
 * The "Which item is this barcode?" dialog opens only for a real scan: input
 * from the scan detector, or typed text that is all digits with 8–14
 * characters (an EAN / UPC typed by hand) and matches nothing. Plain text plus
 * Enter never opens it (a list search only filters).
 *
 * Pure, so the node tests load it on its own.
 */

/** At least this many keystrokes make a scan (the shortest codes are EAN-8). */
export const SCAN_MIN_KEYS = 4;

/** The average gap between a scanner's keystrokes is at most this (ms). */
export const SCAN_MAX_AVERAGE_GAP_MS = 35;

/** No single gap in a scan is longer than this (ms). */
export const SCAN_MAX_GAP_MS = 80;

/**
 * Whether the keystroke times (ms, oldest first) of the text now in the box
 * look like a scanner burst.
 */
export function looksScanned(times: number[]): boolean {
    if (times.length < SCAN_MIN_KEYS) return false;
    let longest = 0;
    for (let i = 1; i < times.length; i += 1) {
        const gap = times[i]! - times[i - 1]!;
        if (gap < 0) return false;
        longest = Math.max(longest, gap);
    }
    const average = (times[times.length - 1]! - times[0]!) / (times.length - 1);
    return average <= SCAN_MAX_AVERAGE_GAP_MS && longest <= SCAN_MAX_GAP_MS;
}

/** An EAN / UPC typed by hand: 8 to 14 digits, nothing else. */
export function isBarcodeLike(text: string | null | undefined): boolean {
    return /^\d{8,14}$/.test(String(text ?? '').trim());
}

/** Whether a code that matched nothing may open the "Which item is this barcode?" dialog. */
export function mayOfferLink(text: string | null | undefined, scanned: boolean): boolean {
    if (String(text ?? '').trim() === '') return false;
    return scanned || isBarcodeLike(text);
}

/**
 * The keystroke times kept for the text in the box after one input event:
 * one character added = one keystroke; anything else (a paste, a deletion,
 * a cleared box) starts again, so a pasted code is never "scanned".
 */
export function nextTimes(times: number[], now: number, oldLength: number, newLength: number): number[] {
    if (newLength !== oldLength + 1) return [];
    return [...times, now].slice(-64);
}

/** Whether the whole text in the box came from one scanner burst. */
export function wasScanned(times: number[], text: string): boolean {
    const length = text.length;
    return length >= SCAN_MIN_KEYS && times.length >= length && looksScanned(times.slice(-length));
}

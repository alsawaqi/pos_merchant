/**
 * Human-readable duration formatting for the Tables insights (and any
 * other "elapsed seconds" display). Compact by design: "1h 23m", "45m",
 * "30s". Returns an em-dash for null / non-positive input so empty cells
 * read cleanly. Unit letters stay Latin (h/m/s) — they're universally
 * legible and match the app's Latin chart numerals.
 */
export function fmtDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds <= 0) {
        return '—';
    }
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m`;
    return `${s}s`;
}

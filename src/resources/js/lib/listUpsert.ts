/**
 * Fix order B-2 (the orchestrator's browser check, item 6) — a list shows
 * what was just saved at once: the saved row (the server's answer) replaces
 * the row with its uuid, or joins the list in name order (the server lists
 * by name). The list is re-read afterwards; this makes the new item appear
 * even when that re-read is slow, fails, or an older read lands last.
 * Pure, so the node tests load it on its own.
 */
export function upsertByUuid<T extends { uuid: string; name: string }>(list: T[], item: T): T[] {
    const at = list.findIndex((row) => row.uuid === item.uuid);
    if (at >= 0) return list.map((row, i) => (i === at ? item : row));
    const name = item.name.toLocaleLowerCase();
    const before = list.findIndex((row) => row.name.toLocaleLowerCase() > name);
    return before < 0 ? [...list, item] : [...list.slice(0, before), item, ...list.slice(before)];
}

/**
 * A guard for a list that is re-read from several places: only the LATEST
 * read may write the list (an older one landing last is dropped).
 */
export function latestOnly(): { start(): number; isLatest(ticket: number): boolean } {
    let seq = 0;
    return {
        start: () => {
            seq += 1;
            return seq;
        },
        isLatest: (ticket: number) => ticket === seq,
    };
}

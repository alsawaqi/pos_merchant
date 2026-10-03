/**
 * LAUNCH-P4 B5 — the placeholder shown for an item without a photo: the first
 * letter of its first two words ("Chicken Shawarma" → "CS", "شاي كرك" → "شك").
 * No imports, so the node tests can load this file as is.
 */
export function initialsOf(name: string | null | undefined): string {
    const words = String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter((w) => w !== '');
    if (words.length === 0) return '?';
    const letters = words.slice(0, 2).map((w) => Array.from(w)[0] ?? '');
    return letters.join('').toUpperCase();
}

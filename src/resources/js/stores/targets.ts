/**
 * P-G8 — once-per-session guard for the "a branch just missed its
 * target" popup. Module-scoped, so it survives route changes within the
 * SPA session and resets on the next full page load (i.e. the next
 * portal login / refresh — matching "popup on portal login").
 */

let missPopupShown = false;

export function shouldShowMissPopup(): boolean {
    return !missPopupShown;
}

export function markMissPopupShown(): void {
    missPopupShown = true;
}

/**
 * Defeats Chrome/Safari/Firefox back-forward-cache restoration of
 * a previously-authenticated SPA shell after logout.
 *
 * When a user logs out + the browser navigates to /login, pressing
 * Back could restore the in-memory state of the previous merchant
 * shell (including the cached auth store) without any HTTP round
 * trip. This listener fires on bfcache restore and forces a fresh
 * navigation — the server-side EnsureUserIsAuthenticated then
 * does the right thing.
 *
 * Pair with PreventBackHistoryCache middleware which sets the HTTP
 * headers (Cache-Control: no-store + Vary: Cookie) that
 * disqualify the response from the persisted bfcache in the first
 * place; this listener handles the edge case where a browser
 * cached the response anyway.
 */
export function installBfcacheGuard(): void {
    window.addEventListener('pageshow', (event) => {
        const restored = (event as PageTransitionEvent).persisted;
        if (restored) {
            // Force a fresh navigation — bypasses the bfcache and
            // re-hits the server which evaluates the current
            // session cookie.
            window.location.reload();
        }
    });
}

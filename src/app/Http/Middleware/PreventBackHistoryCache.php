<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Globally forbids the browser from reusing any response.
 *
 *   - Cache-Control: no-store — must never be saved to disk or
 *     memory cache, and disqualified from bfcache by well-behaved
 *     browsers.
 *   - Vary: Cookie — keys cached entries by session cookie so a
 *     logged-out browser can't reuse a logged-in cached response.
 *   - Pragma: no-cache + Expires: 0 — belt-and-braces for old
 *     HTTP/1.0 intermediaries.
 *
 * Mirrors pos_admin's middleware exactly so both apps present the
 * same caching posture.
 */
class PreventBackHistoryCache
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $existing = (string) $response->headers->get('Cache-Control', '');

        if (! str_contains($existing, 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        }

        if (! $response->headers->has('Pragma')) {
            $response->headers->set('Pragma', 'no-cache');
        }

        if (! $response->headers->has('Expires')) {
            $response->headers->set('Expires', '0');
        }

        $this->addVaryCookie($response);

        foreach ($this->legacySessionCookies() as $cookieName) {
            $response->headers->setCookie(Cookie::forget($cookieName));
        }

        return $response;
    }

    private function addVaryCookie(Response $response): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        $values = array_filter(array_map('trim', explode(',', $existing)));

        if (in_array('Cookie', $values, true)) {
            return;
        }

        $values[] = 'Cookie';
        $response->headers->set('Vary', implode(', ', $values));
    }

    /**
     * @return list<string>
     */
    private function legacySessionCookies(): array
    {
        $currentSessionCookie = (string) config('session.cookie');

        return array_values(array_filter([
            'laravel_session',
            'mithqal-pos-merchant-session',
            'pos_merchant_session',
        ], static fn (string $cookieName): bool => $cookieName !== $currentSessionCookie));
    }
}

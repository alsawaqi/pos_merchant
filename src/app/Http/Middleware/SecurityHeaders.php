<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response. Mirrors pos_admin's
 * version exactly so both apps present the same posture to a
 * browser; the CSP is permissive enough for the Vite dev server
 * during development and tight for production.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a per-response CSP nonce BEFORE the view renders, so the
        // @vite bundle tags + the app.blade.php bootstrap <script> (which
        // injects the initial auth state) can carry it. Without it the
        // production CSP blocks that inline script and the SPA never learns
        // who is signed in — producing an endless /login redirect loop.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $isProduction = app()->isProduction();
        $viteHttpOrigins = $this->viteHttpOrigins();
        $viteWsOrigins = $this->viteWebsocketOrigins($viteHttpOrigins);

        // Production: scripts from 'self' + the per-request nonce (carried by
        // the @vite bundle tags and the app.blade.php bootstrap <script>). Dev
        // keeps 'unsafe-inline'/'unsafe-eval' for the Vite HMR client.
        $nonce = Vite::cspNonce();
        $nonceSource = ($nonce !== null && $nonce !== '') ? " 'nonce-{$nonce}'" : '';

        $scriptSrc = $isProduction
            ? "'self'".$nonceSource
            : trim("'self' 'unsafe-inline' 'unsafe-eval' ".implode(' ', $viteHttpOrigins));

        $styleSrc = $isProduction
            ? "'self' 'unsafe-inline'"
            : trim("'self' 'unsafe-inline' ".implode(' ', $viteHttpOrigins));

        $connectSrc = $isProduction
            ? "'self'"
            : trim("'self' ".implode(' ', [...$viteHttpOrigins, ...$viteWsOrigins]));

        $imgSrc = $isProduction
            ? "'self' data: blob:"
            : trim("'self' data: blob: ".implode(' ', $viteHttpOrigins));

        $fontSrc = $isProduction
            ? "'self' data:"
            : trim("'self' data: ".implode(' ', $viteHttpOrigins));

        // Cloudflare auto-injects its Web Analytics beacon when the zone is
        // proxied (orange-cloud). Allow it so it doesn't spam CSP violations.
        $scriptSrc = trim($scriptSrc.' https://static.cloudflareinsights.com');
        $connectSrc = trim($connectSrc.' https://cloudflareinsights.com');

        return implode('; ', array_filter([
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            // P-G9 — the device Live dialog embeds a keyless Google Maps
            // iframe; without an explicit frame-src it would inherit
            // default-src 'self' and the browser would refuse to frame it.
            // Both hosts listed because the embed endpoint has historically
            // redirected between maps.google.com and www.google.com/maps.
            "frame-src 'self' https://www.google.com https://maps.google.com",
            "object-src 'none'",
            "img-src {$imgSrc}",
            "font-src {$fontSrc}",
            "script-src {$scriptSrc}",
            "script-src-elem {$scriptSrc}",
            "style-src {$styleSrc}",
            "style-src-elem {$styleSrc}",
            "connect-src {$connectSrc}",
        ]));
    }

    /**
     * @return list<string>
     */
    private function viteHttpOrigins(): array
    {
        $candidates = [(string) env('VITE_DEV_SERVER_URL', '')];

        foreach (explode(',', (string) env('VITE_DEV_SERVER_CORS_ORIGINS', '')) as $entry) {
            $candidates[] = $entry;
        }

        $origins = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $origins[$candidate] = true;
        }

        return array_keys($origins);
    }

    /**
     * @param  list<string>  $httpOrigins
     * @return list<string>
     */
    private function viteWebsocketOrigins(array $httpOrigins): array
    {
        $wsOrigins = [];
        foreach ($httpOrigins as $origin) {
            if (str_starts_with($origin, 'https://')) {
                $wsOrigins['wss://'.substr($origin, 8)] = true;
            } elseif (str_starts_with($origin, 'http://')) {
                $wsOrigins['ws://'.substr($origin, 7)] = true;
            }
        }

        return array_keys($wsOrigins);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects already-authenticated visitors away from guest-only
 * pages (e.g. /login). If the current request is XHR, returns
 * 204 so the SPA can update state without a navigation.
 */
class RedirectIfAuthenticated
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            if ($request->expectsJson()) {
                return response()->noContent();
            }

            return new RedirectResponse('/');
        }

        return $next($request);
    }
}

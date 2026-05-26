<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects non-JSON callers on endpoints that are XHR-only (e.g.
 * /auth/csrf, /auth/user). Stops bots + accidental browser
 * navigations from getting weird JSON-shaped error pages.
 */
class RequireJsonRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->expectsJson()) {
            return new JsonResponse([
                'message' => 'This endpoint requires Accept: application/json.',
            ], Response::HTTP_NOT_ACCEPTABLE);
        }

        return $next($request);
    }
}

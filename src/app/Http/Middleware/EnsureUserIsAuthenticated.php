<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project-owned authentication gate. Same shape as Laravel's
 * Authenticate middleware but explicit about the redirect target
 * + the JSON 401 contract — keeps the auth boundary visible right
 * next to the routes and survives any future framework rename.
 */
class EnsureUserIsAuthenticated
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        return $this->unauthenticated($request);
    }

    private function unauthenticated(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest(route('login'));
    }
}

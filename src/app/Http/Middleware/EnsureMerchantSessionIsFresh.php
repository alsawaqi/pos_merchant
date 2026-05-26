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
 * Sliding idle-timeout enforcement on the merchant `web` guard.
 *
 * Reads the configured timeout from {@see config('pos_merchant_auth.session.idle_timeout_minutes')}
 * (default 30) and compares against `pos_merchant.last_activity_at`
 * stamped on every authenticated request. When exceeded — unless
 * the user clicked "remember me" — the session is invalidated and
 * the request returns 401 (JSON) or a redirect to /login (form).
 *
 * Mirrors pos_admin's EnsurePosAdminSessionIsFresh exactly.
 */
class EnsureMerchantSessionIsFresh
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $session = $request->session();
        $remembered = (bool) $session->get('pos_merchant.remembered', false);
        $lastActivityAt = (int) $session->get('pos_merchant.last_activity_at', now()->timestamp);
        $timeoutSeconds = ((int) config('pos_merchant_auth.session.idle_timeout_minutes')) * 60;

        if (! $remembered && $timeoutSeconds > 0 && (now()->timestamp - $lastActivityAt) > $timeoutSeconds) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return $this->expiredResponse($request);
        }

        $session->put('pos_merchant.last_activity_at', now()->timestamp);

        return $next($request);
    }

    private function expiredResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest(route('login'));
    }
}

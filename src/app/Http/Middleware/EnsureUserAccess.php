<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $fresh = User::query()->find($user->id);
            if ($fresh !== null && Auth::guard('web')->viaRemember()) {
                $request->session()->put('pos.auth_version', (int) $fresh->auth_version);
            }
            $valid = $fresh !== null && $fresh->user_type === 'merchant' && $fresh->status === 'active'
                && (int) $fresh->auth_version === (int) $request->session()->get('pos.auth_version', 0);
            if (! $valid) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $response = $request->expectsJson()
                    ? response()->json(['message' => 'Your access has ended. Please sign in again.'], 401)
                    : redirect('/login');

                return $response->withCookie(Cookie::forget(Auth::guard('web')->getRecallerName()));
            }
            // LAUNCH-P1 P1-13 (decision B7): onboarding merchants work;
            // only suspended / inactive (or a missing company) block,
            // each with its own message.
            $denial = CompanyAccess::denial($fresh->company_id);
            if ($denial !== null) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => $denial['message'], 'code' => $denial['code']], 403)
                    : redirect('/login')->withErrors(['email' => $denial['message']]);
            }
            Auth::guard('web')->setUser($fresh);
            if ($fresh->must_change_password && ! $request->is(
                'auth/user', 'auth/logout', 'auth/change-password', 'auth/csrf', 'auth/login', 'change-password'
            )) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Change your password before continuing.', 'code' => 'password_change_required'], 403)
                    : redirect('/change-password');
            }
        }

        return $next($request);
    }
}

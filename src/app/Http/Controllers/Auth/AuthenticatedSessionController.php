<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Dual-mode session controller for the merchant portal.
 *
 * Mirrors pos_admin's AuthenticatedSessionController but trimmed:
 * no JWT cookie issuance (session auth is enough for v1; JWT can
 * layer in later if a separate token consumer needs it).
 *
 * The login form is a native HTML POST so the browser handles the
 * boundary crossing (no XHR + window.location race). Methods
 * detect whether the caller is XHR or form submission and respond
 * accordingly:
 *
 *   - XHR (Accept: application/json): JSON payload.
 *   - Form: 302 redirect to / on success, redirect-back with
 *     flashed errors on failure.
 *
 * Critical safety rail: only `user_type='merchant'` rows are
 * accepted. A platform admin credential pair would otherwise pass
 * the auth attempt (same hashed password) and silently grant them
 * an unrelated user_type into the merchant portal.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $alreadyAuthed = Auth::guard('web')->check();

        if (! $alreadyAuthed) {
            // Rate limit is checked + incremented only around the
            // credential attempt itself. Successful logins clear
            // the counter so testing and any legitimate retry do
            // not eat the quota.
            $request->ensureIsNotRateLimited();

            // Restrict the candidate user pool to merchant rows
            // BEFORE attempting the password check, otherwise a
            // platform admin's credentials would briefly satisfy
            // Auth::attempt before a downstream check kicks in.
            $candidate = User::query()
                ->merchant()
                ->where('email', $request->credentials()['email'])
                ->first();

            $passwordOk = $candidate !== null
                && Auth::guard('web')->validate($request->credentials())
                && $candidate->user_type === 'merchant';

            if (! $passwordOk) {
                RateLimiter::hit($request->throttleKey(), 60);

                return $this->failedLogin($request);
            }

            Auth::guard('web')->login($candidate, $request->remember());
            RateLimiter::clear($request->throttleKey());
            $request->session()->regenerate();
        }

        $request->session()->put('pos_merchant.remembered', $request->remember());
        $request->session()->put('pos_merchant.last_activity_at', now()->timestamp);

        /** @var User $user */
        $user = Auth::guard('web')->user();

        // Update last_login_at on the shared user row so pos_admin's
        // Portal Users tab + audit log reflect activity. Use the
        // query builder to avoid touching updated_at (which would
        // race with concurrent admin updates from pos_admin).
        DB::table('pos_users')
            ->where('id', $user->id)
            ->update(['last_login_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json([
                'user' => $this->userPayload($user),
                'session' => $this->sessionPayload($request),
            ]);
        }

        return redirect()->intended('/');
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'session' => $this->sessionPayload($request),
        ]);
    }

    public function destroy(Request $request): Response|RedirectResponse
    {
        Auth::guard('web')->logout();

        // Destroy the session in the configured driver, generate a
        // new session id, rotate the CSRF token so any leaked-token
        // replay is dead on arrival.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = $request->expectsJson()
            ? response()->noContent()
            : redirect('/login');

        // Defence in depth — actively expire the recaller cookie
        // (Laravel's "remember me") so the next request from this
        // client is unambiguously anonymous.
        $response->withCookie(Cookie::forget(Auth::guard('web')->getRecallerName()));

        // Drop the back/forward cache so a Back press after logout
        // can NEVER restore the previously-rendered shell.
        // Cache-Control / Pragma / Expires are stamped by
        // PreventBackHistoryCache.
        $response->headers->set('Clear-Site-Data', '"cache"');

        return $response;
    }

    /**
     * @throws ValidationException
     */
    private function failedLogin(LoginRequest $request): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return back()
            ->withErrors(['email' => __('auth.failed')])
            ->withInput($request->only('email', 'remember'));
    }

    /**
     * @return array{id: int|string|null, name: string|null, email: string|null, user_type: string|null, status: string|null, company_id: int|null, locale: string|null, roles: list<string>, permissions: list<string>}
     */
    private function userPayload(User $user): array
    {
        // Pull roles + permissions under the user's company team
        // scope so the SPA's can() / hasRole() helpers can mirror
        // server-side gates without an extra round-trip.
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        if ($user->company_id !== null) {
            $registrar->setPermissionsTeamId((int) $user->company_id);
        }

        try {
            $roles = $user->getRoleNames()->all();
            $permissions = $user->getAllPermissions()->pluck('name')->all();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'status' => $user->status,
            'company_id' => $user->company_id,
            'locale' => $user->locale,
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
        ];
    }

    /**
     * @return array{idle_timeout_minutes: int, csrf_token: string}
     */
    private function sessionPayload(Request $request): array
    {
        return [
            'idle_timeout_minutes' => (int) config('pos_merchant_auth.session.idle_timeout_minutes'),
            'csrf_token' => $request->session()->token(),
        ];
    }
}

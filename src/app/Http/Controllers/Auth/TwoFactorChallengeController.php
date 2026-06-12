<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteTwoFactorChallengeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * The second step of a 2FA-enrolled login (Phase D8).
 *
 * AuthenticatedSessionController::store parks the pending state in
 * the session (NO session/user established) and bounces the browser
 * to /two-factor-challenge; this controller is the ONLY place that
 * pending state can be converted into a real session:
 *
 *   GET  /auth/two-factor-challenge  is a challenge pending? (the
 *                                    SPA page redirects to /login
 *                                    when nothing is pending)
 *   POST /auth/two-factor-challenge  verify TOTP or burn a recovery
 *                                    code → complete the login
 *                                    exactly like the normal flow
 *
 * Code attempts are throttled per (pending user, IP) like login;
 * a correct code clears the counter. Success regenerates the
 * session id (anti-fixation) before the post-login keys are set.
 */
class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'pending' => $this->pendingUser($request) !== null,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(TwoFactorChallengeRequest $request, CompleteTwoFactorChallengeAction $action): JsonResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            PendingTwoFactorChallenge::clear($request->session());

            throw ValidationException::withMessages([
                'challenge' => [__('Your sign-in attempt expired. Please sign in again.')],
            ]);
        }

        $this->ensureIsNotRateLimited($request, $user);
        RateLimiter::hit($this->throttleKey($request, $user), 60);

        $validated = $request->validated();

        $passed = $action->handle(
            user: $user,
            code: $validated['code'] ?? null,
            recoveryCode: $validated['recovery_code'] ?? null,
        );

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code is invalid.')],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $user));

        $remember = PendingTwoFactorChallenge::remember($request->session());
        PendingTwoFactorChallenge::clear($request->session());

        // From here the flow mirrors AuthenticatedSessionController::
        // store after a password-only success — keep both in sync.
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('pos_merchant.remembered', $remember);
        $request->session()->put('pos_merchant.last_activity_at', now()->timestamp);

        DB::table('pos_users')
            ->where('id', $user->id)
            ->update(['last_login_at' => now()]);

        return response()->json([
            'user' => $this->userPayload($user),
            'session' => [
                'idle_timeout_minutes' => (int) config('pos_merchant_auth.session.idle_timeout_minutes'),
                'csrf_token' => $request->session()->token(),
            ],
        ]);
    }

    /**
     * Resolve the pending user — merchant rows only (the shared
     * pos_users table also hosts platform admins) and still active.
     * NULL when nothing is pending or the challenge expired.
     */
    private function pendingUser(Request $request): ?User
    {
        $userId = PendingTwoFactorChallenge::userId($request->session());

        if ($userId === null) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->merchant()
            ->where('status', 'active')
            ->find($userId);

        if ($user === null || ! $user->hasConfirmedTwoFactor()) {
            return null;
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, User $user): void
    {
        $key = $this->throttleKey($request, $user);
        $max = (int) config('pos_merchant_auth.rate_limits.two_factor_per_minute', 5);

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'code' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ])],
        ]);
    }

    private function throttleKey(Request $request, User $user): string
    {
        return 'two_factor|'.$user->id.'|'.$request->ip();
    }

    /**
     * Same shape as AuthenticatedSessionController::userPayload —
     * keep both in sync.
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        if ($user->company_id !== null) {
            $registrar->setPermissionsTeamId((int) $user->company_id);
        }

        try {
            $roles = $user->getRoleNames()->all();
            $permissions = $user->getAllPermissions()->pluck('name')->all();
            // P-G5 — needs the team pin too (SuperAdmin outranks scope).
            $branchScope = $user->allowedBranchIds();
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
            'must_change_password' => (bool) $user->must_change_password,
            'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
            // P-G5 — null = all branches; list = restricted scope.
            'branch_scope' => $branchScope,
        ];
    }
}

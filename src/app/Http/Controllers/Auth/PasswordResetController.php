<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public self-service forgot/reset password endpoints (Phase D7).
 *
 * forgot — ALWAYS answers 200 so the response can't be used to
 * enumerate which emails have accounts; the real work (token mint
 * + email) happens silently inside SendPasswordResetLinkAction and
 * only for active merchant rows.
 *
 * reset — consumes the emailed token; every failure mode returns
 * one generic message.
 *
 * Both endpoints are rate limited per (email, IP) the same way
 * login is — failed/abusive callers hit the limiter, a successful
 * reset clears it. Window: pos_merchant_auth.rate_limits
 * .password_reset_per_quarter_hour attempts per 15 minutes.
 */
class PasswordResetController extends Controller
{
    /** 15-minute decay on the (email, IP) throttle bucket. */
    private const THROTTLE_DECAY_SECONDS = 900;

    /**
     * @throws ValidationException
     */
    public function forgot(ForgotPasswordRequest $request, SendPasswordResetLinkAction $action): JsonResponse
    {
        $this->ensureIsNotRateLimited($request, 'forgot');
        RateLimiter::hit($this->throttleKey($request, 'forgot'), self::THROTTLE_DECAY_SECONDS);

        $action->handle((string) $request->string('email'));

        // Identical response whether or not the email exists.
        return response()->json([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function reset(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $this->ensureIsNotRateLimited($request, 'reset');
        RateLimiter::hit($this->throttleKey($request, 'reset'), self::THROTTLE_DECAY_SECONDS);

        $validated = $request->validated();

        $action->handle(
            email: $validated['email'],
            rawToken: $validated['token'],
            password: $validated['password'],
        );

        // Successful resets don't eat the quota (mirrors login).
        RateLimiter::clear($this->throttleKey($request, 'reset'));

        return response()->json([
            'message' => 'Password has been reset.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, string $bucket): void
    {
        $key = $this->throttleKey($request, $bucket);

        if (! RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Keyed per (email, IP) + bucket so forgot-spam and reset
     * brute-force burn separate quotas, and an attacker hammering
     * many emails from one IP is keyed apart from a legitimate
     * user retrying their own. Mirrors LoginRequest::throttleKey.
     */
    private function throttleKey(Request $request, string $bucket): string
    {
        $email = Str::lower((string) $request->string('email'));

        return 'password_reset|'.$bucket.'|'.Str::transliterate($email.'|'.$request->ip());
    }

    private function maxAttempts(): int
    {
        return (int) config('pos_merchant_auth.rate_limits.password_reset_per_quarter_hour', 5);
    }
}

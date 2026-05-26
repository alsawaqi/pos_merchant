<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates the merchant login payload + enforces the per-(email, ip)
 * rate limit. Mirrors pos_admin's LoginRequest shape so both apps
 * stay in lockstep; the only difference is the config namespace
 * (`pos_merchant_auth.*` instead of `pos_admin_auth.*`).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the request before validation so both XHR (JSON) and
     * native HTML form submissions reach the same shape. Checkbox
     * inputs arrive as "on" when ticked or are absent when not —
     * Laravel's boolean rule does not accept "on", hence the
     * explicit coercion.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'remember' => $this->boolean('remember'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->string('email')->lower(),
            'password' => (string) $this->string('password'),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }

    /**
     * Bail if the user has tripped the failed-login rate limit.
     * Successful logins do NOT consume the quota — only failed
     * attempts call RateLimiter::hit in the controller. Lets
     * legitimate retries through while defending against
     * credential stuffing on a single email/IP pair.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxLoginAttempts())) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Unique per (email, IP) so an attacker IP attempting many
     * emails gets keyed separately from a legitimate user who
     * mistyped one password. Lowercase + transliterate to
     * neutralise homograph tricks.
     */
    public function throttleKey(): string
    {
        $email = Str::lower((string) $this->string('email'));

        return Str::transliterate($email.'|'.$this->ip());
    }

    public function maxLoginAttempts(): int
    {
        return (int) config('pos_merchant_auth.rate_limits.login_per_minute', 5);
    }
}

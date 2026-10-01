<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates POST /auth/reset-password — used by the forgot-password
 * page AND the /setup-password page a new merchant reaches from the
 * admin's set-password link (LAUNCH-P1 P1-2). Password rules: at least
 * 8 characters with letters and numbers, confirmed. The token's
 * validity is checked in ResetPasswordAction with a single generic
 * failure message.
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(8)->letters()->numbers()],
        ];
    }
}

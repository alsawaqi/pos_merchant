<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /auth/reset-password. The password rules mirror
 * the change-password feature (confirmed, >= 8 chars); the token's
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}

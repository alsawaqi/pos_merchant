<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /auth/forgot-password. Shape-only — whether the
 * email maps to a real account is deliberately NOT validated here
 * (the endpoint answers 200 either way, anti-enumeration).
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
        ];
    }
}

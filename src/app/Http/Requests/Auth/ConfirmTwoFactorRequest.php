<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /auth/two-factor/confirm (Phase D8) — the
 * enrolment-finishing code. Shape only; the cryptographic check
 * happens in ConfirmTwoFactorAction.
 */
class ConfirmTwoFactorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Authenticator apps display "123 456" — accept it.
        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates DELETE /auth/two-factor (Phase D8). Disabling demands a
 * step-up: the current password PLUS one proof-of-second-factor —
 * either a live TOTP code or an unused recovery code. Shape only;
 * the actual checks happen in DisableTwoFactorAction.
 */
class DisableTwoFactorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
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
            'current_password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'digits:6', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32', 'required_without:code'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /auth/two-factor-challenge (Phase D8) — the code
 * step after a 2FA-enrolled user's password check passed. Exactly
 * one of {code, recovery_code} must be present; which one decides
 * the verification path in CompleteTwoFactorChallengeAction.
 */
class TwoFactorChallengeRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'digits:6', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32', 'required_without:code'],
        ];
    }
}

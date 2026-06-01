<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /auth/change-password. The current-password match is
 * checked in the controller (guard-agnostic Hash::check) so this only
 * validates shape: a confirmed new password >= 8 chars that differs
 * from the current one.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];
    }
}

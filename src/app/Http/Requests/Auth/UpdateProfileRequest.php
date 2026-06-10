<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /auth/profile. Name only in v1 — email is the
 * (admin-managed, globally unique) login identifier and phone is
 * encrypted PII managed via the Portal Users surface, so neither
 * is self-service editable.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

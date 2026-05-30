<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\SavedViews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate creating a saved view. Per-user uniqueness of (view_key, name) is
 * enforced in the controller against the authenticated user's own rows.
 */
class StoreSavedViewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'view_key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}

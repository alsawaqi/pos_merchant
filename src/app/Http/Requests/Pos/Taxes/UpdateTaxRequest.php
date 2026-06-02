<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Taxes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/taxes/{uuid}.
 *
 * All fields optional (partial update). Uniqueness check on name lives in the
 * Action.
 */
class UpdateTaxRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rate_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

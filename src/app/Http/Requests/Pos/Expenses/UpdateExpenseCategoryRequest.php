<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/expense-categories/{uuid}.
 *
 * All fields optional (partial update). Uniqueness check on name lives in the
 * Action; the key is immutable and never accepted here.
 */
class UpdateExpenseCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

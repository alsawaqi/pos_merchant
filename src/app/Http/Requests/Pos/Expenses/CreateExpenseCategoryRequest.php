<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/expense-categories.
 *
 * Name required (uniqueness + key derivation handled in the Action).
 */
class CreateExpenseCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

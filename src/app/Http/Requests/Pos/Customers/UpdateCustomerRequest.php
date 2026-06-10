<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/customers/{customer:uuid}.
 *
 * All fields are optional (partial update) but if present must
 * be valid. Phone uniqueness check happens in the Action —
 * keeping all of that logic in one place.
 *
 * Phase D3 — date_of_birth + tags accept explicit null to CLEAR
 * the value (vs. omitting the key = leave untouched).
 */
class UpdateCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:32', 'distinct:ignore_case'],
        ];
    }
}

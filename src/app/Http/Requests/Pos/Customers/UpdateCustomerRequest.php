<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/customers/{customer:uuid}.
 *
 * Both fields are optional (partial update) but if present must
 * be non-empty strings. Phone uniqueness check happens in the
 * Action — keeping all of that logic in one place.
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
        ];
    }
}

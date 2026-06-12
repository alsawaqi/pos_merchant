<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAddOnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'price_delta' => ['sometimes', 'numeric', 'min:0', 'max:999.999'],
            'is_default' => ['sometimes', 'boolean'],
            // P-G3 — link/unlink the real product behind this option
            // (null = back to a classic label-only add-on).
            'linked_product_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\OrderReasons;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/comp-reasons and PATCH /api/comp-reasons/{uuid}.
 * max_amount caps a single comp under this reason (OMR; null/blank
 * clears the cap).
 */
class SaveCompReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

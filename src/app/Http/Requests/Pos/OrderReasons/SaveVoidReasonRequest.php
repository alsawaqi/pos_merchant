<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\OrderReasons;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/void-reasons and PATCH /api/void-reasons/{uuid}.
 * `code` is server-minted on create and immutable — never accepted
 * from the client. Duplicate-name friendliness lives in the Action.
 */
class SaveVoidReasonRequest extends FormRequest
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
            'affects_inventory' => ['sometimes', 'boolean'],
            'requires_manager' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

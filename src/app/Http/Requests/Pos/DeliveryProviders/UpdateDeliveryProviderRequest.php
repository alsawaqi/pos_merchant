<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\DeliveryProviders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/delivery-providers/{uuid}.
 *
 * All fields optional (partial update). Uniqueness check on
 * name lives in the Action.
 */
class UpdateDeliveryProviderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
